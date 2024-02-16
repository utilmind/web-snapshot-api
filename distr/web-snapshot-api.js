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

    DEF_STORAGE_DIR = "_storage", // no trailing slashes please. Alternatively (and preferable) specify STORAGE_DIR constant in .env. If STORAGE_DIR is omitted, current directory path.join(__dirname, DEF_STORAGE_DIR) used as the root directory for storage.

    // MODULES
    // -------
    express = require('express'),
    bodyParser = require('body-parser'),
    puppeteer = require('puppeteer'),
    dotenv = require('dotenv').config(),
    path = require('path'), // for path.join(), using system-specific path delimiter
    fs = require('fs'), // for mkdir()
    { v4: uuidv4 } = require('uuid'),
    mysql = require('mysql2/promise'), // we don't want async/await, would prefer callback style

    app = express(),
    port = 3000,

    // MySQL uses varaibles from .env. BTW we also can specify directory to store snapshot files as STORAGE_DIR constant. 
    dbPool = mysql.createPool({ // the following variables (credentials) described in ".env", which not provided in repository
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
                            : v,

    storageDirName = fileId => (Math.floor(fileId / 1000) * 1000).toString(),

    // The only response in text/html format. Others are JSONs.
    methodNotAllowed = x => res.set('Content-Type', 'text/html')
                                .status(405)
                                .send('<h1>405 Method Not Allowed</h1>');

    // on Success: .then(clientId, dbConnection)
    // on Failure: .catch(httpStatus, errorReason)
    authenticate = (accessKey, needDb) => { // needDb = don't release db connection if TRUE.
        const ERR_INVALID_ACCESS_KEY = 'Invalid access key. You have limited number of attempts before your IP will be banned.';

        // Check Authorzation first. We require explicit "Bearer" scheme, in compliance with https://www.rfc-editor.org/rfc/rfc6750
        accessKey = accessKey.split(' ', 2); // [Authentication Scheme] [Access Key]. We support Bearer scheme only. Access Key must contain valid base64 characters only.

        if (('Bearer' !== accessKey[0]) // we don't want to check db if key length is less than allowed minimum. And yes, even scheme name ("Bearer") is case sensitive here. Consider this as part of the token :)
                || !(accessKey = accessKey[1])
                || !/^[A-Za-z\d+/]+={0,2}$/.test(accessKey)) { // Are characters valid for base64 encoding? (No bad characters? We don't want to check them in DB + keys with non-base64 encoding characters are not really Bearer-compliant.)

            return Promise.reject([403, 'Authorization required by Bearer scheme.']);
        }

        if (accessKey.length < MIN_ACCESS_KEY_LEN) { // we don't want to check db if key length is less than allowed minimum.
            // Although we warn user that numer of request attempts are limited, we don't want to log this request in DB, if length is less than required. Just ignore this.
            return Promise.reject([403, ERR_INVALID_ACCESS_KEY]);
        }

        let db; // this variable can be returned in case of successful authentication, if needDb is true.
        //clientId; // filled if query was successful
            
        return dbPool
            .getConnection() // Use unprepeared query here. It will not be reused within current connection anyway. And accessKey doesn't contain characters that may allow SQL injection.
            .then(_db => {
                (db = _db).query(`SELECT id FROM web_snapshot_api_client WHERE 'key'=${accessKey} AND active=1`) // safe. We sanitized bad characters above.
            })
            .then(([rows]) => {
                if (rows.length) { // non-fatal error, just correct accessKey not found
                    clientId = rows[0].id;
                    if (!needDb) db.release();
                    return needDb ? [clientId, db] : clientId;
                }

                db.release();
                console.error('Invalid access key', accessKey); // TODO: set up the limit!
                return Promise.reject([403, ERR_INVALID_ACCESS_KEY]);

            }).catch(err => {
                if (db) db.release();

                console.error('MySQL error during authentication.', err.message);
                return Promise.reject([500, "Temporarily can't validate access key."]);
            });
    };


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

/*
    Supported methods:
        /snapshot: take the snapshot of an URL, save the image file on the server's storage and return image file (as link or binary) to client.
        /list: returns the list of existing snapshots of certain URL
        /remove: delete snapshot from the server's storage. (* Each client identified by access key can remove only own images.)
*/


/*  /snapshot:
    Accepted parameters. URL is required, all others are optional. Default values will be used if they are not specified.
        url: (string) Required parameter.
        width: (float) Default is DEF_PAGE_WIDTH.
        height: (float) Default is DEF_PAGE_HEIGHT.
        full_page: (bool) Default is true/1. Set to false/0 to disable.
        format: (string) Default is DEF_IMAGE_FORMAT. Can be eighter PNG, JPG or WEBP. Case insensitive. Filename created with lowercase extension.
        get: (string) Default is 'url'. Other methods not implemented on 2024-02-14. TODO: either URL to the filename with snapshot (url) OR base64-encoded binary data of the image (base64).

    This server holds the snapshot in its storage. The following parameters allow to manage snapshot in storage.
        valid_time: (int) in seconds. Default is 7 * 24 * 60 * 60 (eg 7 days = 604800 seconds).
                    Timeout in seconds of validity of existing snapshot. Set to 0 always retreive fresh screenshot, or 3600 (60*60 seconds) to retreive fresh screenshot not often than once per hour.

        overwrite: (bool) Default is FALSE. Overwrite LAST existing (previous) snapshot of an URL, or create new record.
                    TRUE = overwrite last snapshot, FALSE (default) = create a new record, leaving existing snapshot(s) in archive.

        expire: (int OR string) with the Unix timestamp OR DATE in YYYY-MM-DD HH:MM:SS(+TZ) format. Default timzone is UTC (GMT+0).
                    Timestamp when the file should be expired and deleted from server storage.
                    NOTE: garbage collector removes mages once per day.

    Response:
        Successfull response is HTTP code 201 (snapshot created).
        Unsuccessful -- 400, 403, 500, 507, depending on the cause of the error.
*/
app.route('/snapshot')
    .post((req, res) => {
        const data = req.body,
            url = data.url;

        // We don't want to connect to mySQL if URL not provided. Certanily bad request.
        if (!url) {
            return res.status(400).json({ error: "'url' is required." });
        }

        authenticate(req.headers['authorization'])
            .then(clientId => {

                console.log('OK');
                res.status(201).json({ url });
                return;

                let width = Math.abs(fl0at(data.width, DEF_PAGE_WIDTH)),
                    height = Math.abs(fl0at(data.height, DEF_PAGE_HEIGHT)),
                    format = data.format;

                if (width > MAX_PAGE_WIDTH) width = MAX_PAGE_WIDTH;
                if (height > MAX_PAGE_HEIGHT) height = MAX_PAGE_HEIGHT;
                if (format) {
                    format.toLowerCase().replace(/[^a-z\d]/g, ''); // strip all non-latin and non-digit characters. But mostly it used to trim possible leading dot.
                    if ('jpeg' === format) format = 'jpg';
                    if (-1 === SUPPORTED_IMAGE_FORMATS.indexOf(format)) {
                        return res.status(400).json({ url, error: `Unsupported image format. Request either: ${SUPPORTED_IMAGE_FORMATS.join(', ')}.` });
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

                                    const fileUniqueName = uuidv4(), // Use additional obfuscation, if needed.
                                        // IP
                                        // AK: alternatively try module 'request-ip'. But this should work already. Also remember, we have X-Real-IP header in Nginx config.
                                        forwardedIps = req.headers['x-forwarded-for'],
                                        ip = forwardedIps ? forwardedIps.split(',')[0] : req.socket.remoteAddress;

                                    dbPool.getConnection((err, db) => {
                                        if (err) throw err;

                                        // inserting INACTIVE record
                                        db.query(`INSERT INTO web_snapshot_api_snapshot SET client=?, url=?, width=${width}, height=${height}, format='${format}', snapshot=?, time=CURRENT_TIMESTAMP, ip=?,active=0`,
                                            [clientId, url, fileUniqueName, ip],
                                            (err, results) => {
                                                console.log(results)
                                                const releaseMem = () => {
                                                        db.release();
                                                        browser.close();
                                                    };

                                                if (err) {
                                                    releaseMem();
                                                    throw err;
                                                }

                                                const recId = results.insertId, // int
                                                    targetDir = path.join(process.env.STORAGE_DIR || path.join(__dirname, DEF_STORAGE_DIR),
                                                                            storageDirName(recId)),
                                                    fn = path.join(targetDir, fileUniqueName + '.' + format);

                                                fs.access(targetDir, err => {
                                                    if (err) { // directory not exists yet
                                                        fs.mkdir(targetDir, { recursive: true }, err => { // trying to create...
                                                            if (err) { // error
                                                                console.error(`Can't create new directory ${targetDir}`);
                                                                releaseMem();
                                                                throw err;
                                                            }
                                                        });
                                                    }

                                                    // take a screenshot only after record will be inserted into db.
                                                    page.screenshot({
                                                        path: fn,
                                                        fullPage: !!data.fullpage // AK: !! used for security, to get only boolean value
                                                    }).then(() => {
                                                        db.query('UDPDATE web_snapshot_api_snapshot SET active=1 WHERE id=' + recId); // safe, int value

                                                        console.log('SNAPSHOT CREATED');
                                                        res.status(201).json({ url, snapshot: fn });
                                                    }).catch(errorReason => {
                                                        console.error('SNAPSHOT FAILURE', errorReason);

                                                        // TODO: think about error 507 Insufficient Storage
                                                        res.status(507).json({ url, error: 'Insufficient Storage' });
                                                    }).finally(() => {
                                                        releaseMem();
                                                    });
                                                });
                                            });
                                    });

                                }).catch(errorReason => {
                                    console.error('NAVIGATION FAILURE', errorReason);
                                    // TODO: write into log

                                    browser.close();
                                    res.status(500).json({ url, error: 'Failed to load URL.' });
                                });
                            })
                            // if new page can't be created for any reason
                            .catch(errorReason => {
                                console.error('Failed to open new page', errorReason);
                                browser.close();
                                res.status(500).json({ url, error: 'Failed to open new page.' });
                            })
                        )
                        .catch(errorReason => {
                            console.error('Failed to launch browser', errorReason);
                            res.status(500).json({ url, error: 'Failed to launch browser.' });
                        });
            })
            .catch((httpStatus, errorReason) => {
                res.status(httpStatus).json({ url, error: errorReason });
            });
    }).all(x => methodNotAllowed);

/*
    Accepted parameters.
        url: (string) Optional. If specified, returned list is all snapshots taken from this URL.
*/
app.route('/list')
    .post((req, res) => {
        const data = req.body,
            url = data.url;

        try {
            dbPool.getConnection((err, db) => {
                if (err) throw err;

                db.query('SELECT id, ' + (url ? 'url, ' : '') + 'snapshot, time FROM web_snapshot_api_client WHERE client= AND active=1', [accessKey], (err, row) => {
                    if (err) throw err;
                });

            });
        }catch(e) {
            console.error('/list MySQL error', err);
            res.status(500).json({ url, error: "Temporarily can't validate access key." });
        }
        
        req.status(503).json({ error: "Not implemented." });
    }).all(x => methodNotAllowed);

app.route('/remove')
    .post((req, res) => {
        req.status(503).json({ error: "Not implemented." });
    }).all(x => methodNotAllowed);

// Start server
app.listen(port, () => {
    console.log(`${appName} running on port ${port}...`);
});
