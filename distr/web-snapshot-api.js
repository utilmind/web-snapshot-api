/* TODO:
        1. Authentication. Process reqests with valid public key.
        2. Send real link to the temporary file.
        3. Delete outdated (unpaid?) snapshot files.
        4. Log requests into mySQL db.

        *. Don't take screenshot of the same site more often than once per 1 day. Or use something like 'force' or 'nocache' parameters.

*/
// CONFIGURATION
// -------------
const appName = 'UtilMind Web Snapshot Maker',
    version = '0.1',
    DEF_IMAGE_FORMAT = 'jpg',
    SUPPORTED_IMAGE_FORMATS = ['jpg', 'png', 'webp'], // other formats may require workarounds: https://screenshotone.com/blog/taking-screenshots-with-puppeteer-in-gif-jp2-tiff-avif-heif-or-svg-format/

    DEF_PAGE_WIDTH = 1920, // FullHD video width
    DEF_PAGE_HEIGHT = 1080, // FullHD vide height, but it's obsolete if we're taking full page.

    MAX_PAGE_WIDTH = 3840, // 4k video
    MAX_PAGE_HEIGHT = 19200, // 4k width * 5

    MIN_ACCESS_KEY_LEN = 32,


    // MODULES
    // -------
    express = require('express'),
    bodyParser = require('body-parser'),
    puppeteer = require('puppeteer'),
    dotenv = require('dotenv').config(),
    path = require('path'), // for path.join(), using system-specific path delimiter
    { v4: uuidv4 } = require('uuid'),
    mysql = require('mysql2'),

    app = express(),
    port = 3000,

    dbPool = mysql.createPool({
            host: process.env.DB_HOST,
            user: process.env.DB_USER,
            password: process.env.DB_PASS,
            database: process.env.DB_NAME,

            // waitForConnections: true, // default is TRUE. Wait if all connections are busy. Don't return error if all are busy (in case of FALSE)
            // connectionLimit: 10, // default is 10. Reserved simultaneous db connections.
            // queueLimit: 0, // 0 = no limit
            // idleTimeout: 60000, // idle connections timeout, in milliseconds, the default value 60000
            // multipleStatements: true, // it's false by default. Uncomment to execute multiple SQL-statements per query.
            // timezone: undefined, // we don't care so far, but can be interested to specify in the future.
        }),


    // PRIVATE FUNCS
    // -------------
    // same as parseFloat, but returns 0 if parseFloat returns non-numerical value
    fl0at = (v, def) => isNaN(v = parseFloat(v))
                            ? (undefined !== def ? def : 0) // "" is good value too. Don't replace with 0 if "" set.
                            : v;


// GO!
// Always send these headers in response to any request
app.use((req, res, next) => { // 3 parameters. Do always.
    res.set({
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'POST', //'GET,PUT,POST,DELETE');
            //'Access-Control-Allow-Headers': 'Content-Type',
            //'Accept': 'application/json, application/x-www-form-urlencoded', // Inform client that we support x-www-form-urlencoded too, although we prefer JSON.
            'Accept': 'application/json', // Accept JSON only
            'Content-Type': 'application/json', // Our responses are in JSON format only. (Except for 405 Method Not Allowed errors, if used not POST method or wrong route.)
            'X-Powered-By': appName + ' v' + version, // or use app.disable('x-powered-by'), to disable this header completely.
        });
    next();
});

// Accept application/json only. ATTN! It will trigger error if HTTP_ACCEPT header doesn't contain 'application/json' or at least '*/*' value.
app.use(bodyParser.json()); // or use app.use(bodyParser.urlencoded({ extended: true })) to receive raw data, then detect and parse JSON additionally. But we really don't want anything but JSON here.

// Error processing during the processing of incoming request.
app.use((error, req, res, next) => { // 4 parameters, 'error' is first, so this block executed only in case of JSON error.
    // if (error instanceof SyntaxError && (400 === error.status) && 'body' in error) // It's odd. We can be here only in case of JSON parse error.
    res.status(400).json({ error: 'Bad request. We expect incoming data in JSON format.' });
});


/* Accepted parameters. URL is required, all others are optional. Default values will be used if they are not specified.
        url: (string) Required parameter.
        width: (float) Default is DEF_PAGE_WIDTH.
        height: (float) Default is DEF_PAGE_HEIGHT.
        full_page: (bool) Default is true/1. Set to false/0 to disable.
        format: (string) Default is DEF_IMAGE_FORMAT. Can be eighter PNG, JPG or WEBP. Case insensitive. Filename created with lowercase extension.
*/
app.route('/snapshot')
    .post((req, res) => {
        const data = req.body,
            url = data.url;

        // We don't want to connect to mySQL if URL not provided. Certanily bad request.
        if (!url) {
            res.status(400).json({ error: '\'url\' is required.' });
            return;
        }

        // Check Authorzation first
        const accessKey = req.headers['authorization'];
        if (!accessKey || (accessKey.length < MIN_ACCESS_KEY_LEN)) { // we don't want to check db if key length is less than allowed minimum.
            res.status(403).json({ url, error: 'Authorization required.' });
            return;
        }

        try {
            dbPool.getConnection((err, db) => {
                if (err) throw err;

                db.query('SELECT id FROM web_snapshot_api_client WHERE `key`=?', [accessKey], (err, row) => {
                    if (err) throw err;

                    if (!row.length) { // non-fatal error, just access key is invalid.
                        console.error('Invalid access key', accessKey);
                        res.status(403).json({ url, error: "Invalid access key. You have limited number of attempts before your IP will be banned." }); // TODO: do the limit!
                        return;
                    }

                    const clientId = row[0].id;
                    let width = Math.abs(fl0at(data.width, DEF_PAGE_WIDTH)),
                        height = Math.abs(fl0at(data.height, DEF_PAGE_HEIGHT)),
                        format = data.format;

                    if (width > MAX_PAGE_WIDTH) width = MAX_PAGE_WIDTH;
                    if (height > MAX_PAGE_HEIGHT) height = MAX_PAGE_HEIGHT;
                    if (format) {
                        format.toLowerCase().replace(/[^a-z\d]/g, ''); // strip all non-latin and non-digit characters. But mostly it used to trim possible leading dot.
                        if ('jpeg' === format) format = 'jpg';
                        if (-1 === SUPPORTED_IMAGE_FORMATS.indexOf(format)) {
                            res.status(400).json({ url, error: `Unsupported image format. Request either: ${SUPPORTED_IMAGE_FORMATS.join(', ')}.` });
                            return;
                        }
                    }else {
                        format = DEF_IMAGE_FORMAT;
                    }

                    puppeteer // AK: I don't want to use async/await methods here.
                        .launch({
                                headless: 'new',
                                args: ['--no-sandbox', '--disable-setuid-sandbox'], // required on Linux, OK for Windows too.
                                ignoreHTTPSErrors: true,
                                defaultViewport: {
                                    width,
                                    height
                                },
                            }).then(browser => browser.newPage()
                                .then(page => {
                                    // go to target website
                                    console.log('Browsing to', url);
                                    page.goto(url, {
                                        // wait for content to load
                                        waitUntil: 'networkidle0' //'domcontentloaded',
                                        //timeout: 0
                                    }).then(() => {
                                        console.log('Successful navigation to', url);

                                        const fn = path.join(__dirname, uuidv4() + '.' + format), // filename
                                            // IP
                                            // AK: alternatively try module 'request-ip'. But this should work already. Also remember, we have X-Real-IP header in Nginx config.
                                            forwardedIps = req.headers['x-forwarded-for'],
                                            ip = forwardedIps ? forwardedIps.split(',')[0] : req.socket.remoteAddress;

                                        dbPool.getConnection((err, db) => {
                                            if (err) throw err;

                                            db.query(`INSERT INTO web_snapshot_api_request_log SET client=?, url=?, width=${width}, height=${height}, format='${format}', snapshot=?, time=CURRENT_TIMESTAMP, ip=?`,
                                                [clientId, url, fn, ip],
                                                (err, results) => {

                                                    if (err) {
                                                        console.error('error execution select:', err);
                                                        throw err;
                                                    }

                                                    console.log('select results', results);
                                                });
                                            db.release();
                                        });

                                        // take a screenshot
                                        page.screenshot({
                                            path: fn,
                                            fullPage: !!data.fullpage // AK: !! used for security, to get only boolean value
                                        }).then(() => {
                                            console.log('SNAPSHOT SUCCESS');
                                            res.status(200).json({ url, snapshot: fn });
                                        }).catch(errorReason => {
                                            console.log('SNAPSHOT FAILURE', errorReason);

                                            // TODO: think about error 507 Insufficient Storage
                                            res.status(507).json({ url, error: 'Insufficient Storage' });
                                        }).finally(() => {
                                            browser.close();
                                        });

                                    }).catch(errorReason => {
                                        console.log('NAVIGATION FAILURE', errorReason);
                                        // TODO: write into log

                                        browser.close();
                                        res.status(500).json({ url, error: 'Failed to load URL.' });
                                    });
                                })
                                // if new page can't be created for any reason
                                .catch(errorReason => {
                                    console.log('Failed to open new page', errorReason);
                                    browser.close();
                                    res.status(500).json({ url, error: 'Failed to open new page.' });
                                })
                            )
                            .catch(errorReason => {
                                console.log('Failed to launch browser', errorReason);
                                res.status(500).json({ url, error: 'Failed to launch browser.' });
                            });
                });
                db.release();
            });
        }catch(e) {
            console.log('MySQL error', err);
            res.status(500).json({ url, error: "Temporarily can't validate access key." });
        }

    }).all((req, res) => {
        //res.error(405, `The ${req.method} method for the "${req.originalUrl}" route is not supported.`);
        res.set('Content-Type', 'text/html')
            .status(405).send('<h1>405 Method Not Allowed</h1>');
    });

// Start server
app.listen(port, () => {
    console.log(`${appName} running on port ${port}...`);
});
