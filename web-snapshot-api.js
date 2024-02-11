const express = require('express'),
    bodyParser = require('body-parser'),
    puppeteer = require('puppeteer'),
    path = require('path'), // for path.join(), using system-specific path delimiter
    { v4: uuidv4 } = require('uuid'),
    //fs = require('fs').promises,

    app = express(),
    port = 3000,

    DEF_IMAGE_FORMAT = 'jpg',
    SUPPORTED_IMAGE_FORMATS = ['jpg', 'png', 'webp'], // other formats may require workarounds: https://screenshotone.com/blog/taking-screenshots-with-puppeteer-in-gif-jp2-tiff-avif-heif-or-svg-format/

    DEF_PAGE_WIDTH = 1920, // FullHD video width
    DEF_PAGE_HEIGHT = 1080, // FullHD vide height, but it's obsolete if we're taking full page.

    MAX_PAGE_WIDTH = 3840, // 4k video
    MAX_PAGE_HEIGHT = 19200, // 4k width * 5

    version = '0.1',


    // PRIVATE FUNCS
    // same as parseFloat, but returns 0 if parseFloat returns non-numerical value
    fl0at = (v, def) => isNaN(v = parseFloat(v))
                            ? (undefined !== def ? def : 0) // "" is good value too. Don't replace with 0 if "" set.
                            : v;


// Accept application/json only
app.use(bodyParser.json()); // or use app.use(bodyParser.urlencoded({ extended: true })) to receive raw data, then detect and parse JSON additionally. But we really don't want anything but JSON here.

// Error processing during the processing of incoming request.
// Also always send some basic headers in response to each query
app.use((error, req, res, next) => {
    // Send these headers even in case of error
    res.append('Access-Control-Allow-Origin', ['*'])
        .append('Access-Control-Allow-Methods', 'POST') //'GET,PUT,POST,DELETE');
        //.append('Access-Control-Allow-Headers', 'Content-Type')
        //.append('Accept', 'application/json, application/x-www-form-urlencoded') // Inform client that we support x-www-form-urlencoded too, although we prefer JSON.
        .append('Accept', 'application/json') // Accept JSON only
        .append('Content-Type', 'application/json') // Our responses are in JSON format only
        .append('X-Powered-By', 'UtilMind Web Snapshot Maker v' + version); // or use app.disable('x-powered-by'), to disable this header completely.

    if (error instanceof SyntaxError && (400 === error.status) && 'body' in error) {
        // AK: we also can hook the incoming data buffer before its processing by bodyParser.json(), but we don't want this. Let's make it in simplest way. No JSON = error.
        res.status(400).json({ error: 'Bad request. We expect incoming data in JSON format.' });

    }else {
        next();
    }
});


/* Accepted parameters. URL is required, all others are optional. Default values will be used if they are not specified.
        url: (string) Required parameter.
        width: (float) Default is DEF_PAGE_WIDTH.
        height: (float) Default is DEF_PAGE_HEIGHT.
        full_page: (bool) Default is true/1. Set to false/0 to disable.
        format: (string) Default is DEF_IMAGE_FORMAT. Can be eighter PNG or JPG. Case insensitive. Filename created with lowercase extension.
*/
app.post('/snapshot', (req, res) => {
    const data = req.body,
        url = data.url;

    if (!url) {
        res.status(400).json({ error: '\'url\' is required.' });
        return;
    }


    let width = Math.abs(fl0at(data.width, DEF_PAGE_WIDTH)),
        height = Math.abs(fl0at(data.height, DEF_PAGE_HEIGHT)),
        format = data.format;

    if (width > MAX_PAGE_WIDTH) width = MAX_PAGE_WIDTH;
    if (height > MAX_PAGE_HEIGHT) height = MAX_PAGE_HEIGHT;
    if (format) {
        format.toLowerCase().replace(/[^a-z\d]/g, ''); // strip all non-latin and non-digit characters. But mostly it used to trim possible leading dot.
        if ('jpeg' === format) format = 'jpg';
        if (-1 === SUPPORTED_IMAGE_FORMATS.indexOf(format)) {
            res.status(400).json({ error: `Unsupported image format. Request either: ${SUPPORTED_IMAGE_FORMATS.join(', ')}.` });
            return;
        }
    }

    (async () => {
        const fn = path.join(__dirname, uuidv4() + '.' + (format || DEF_IMAGE_FORMAT)),

            browser = await puppeteer.launch({
                headless: 'new',
                ignoreHTTPSErrors: true,
                defaultViewport: {
                    width,
                    height
                },
            }),

            // create a new in headless chrome
            page = await browser.newPage();

        // go to target website
        console.log('Browsing to', url);
        await page.goto(url, {
            // wait for content to load
            waitUntil: 'networkidle0' //'domcontentloaded',
            //timeout: 0
        }).then(() => {
            console.log('Success');
        }).catch((res) => {
            console.log('Failure', res);
            // TODO: write into log
        });

        // take a screenshot
        await page.screenshot({
            path: fn,
            fullPage: !!data.fullpage // AK: !! used for security, to get only boolean value
        });

        //todos.push(newTodo);
        res.status(200).json({ url, snapshot: fn });

        //dispose browser
        await browser.close();
    })();
});

// Запуск сервера
app.listen(port, () => {
    console.log(`Server running on port ${port}`);
});
