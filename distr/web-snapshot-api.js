/**
    UtilMind Web Snapshot Maker REST API

    @see       https://github.com/utilmind/web-snapshot-api The GitHub project
    @author    Oleksii Kuznietsov (utilmind) <utilmind@gmail.com>

    TODO:
        1. Authentication. Process reqests with valid public key.
        2. Send real link to the temporary file.
        3. Delete outdated (unpaid?) snapshot files.
        4. Log requests into mySQL db.

        *. Don't take screenshot of the same site more often than once per 1 day. Or use something like 'force' or 'nocache' parameters.

**/
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

    // db fields lengths
    MAX_URL_LENGTH = 255,
    MIN_URL_LENGTH = 10, // we require at least http://a.x for request URL. Otherwise we don't even want to open browser to navigate

    DEF_STORAGE_DIR = "_storage", // no trailing slashes please. Alternatively (and preferable) specify STORAGE_DIR constant in .env. If STORAGE_DIR is omitted, current directory path.join(__dirname, DEF_STORAGE_DIR) used as the root directory for storage.

    DB_TABLE_CLIENT = 'web_snapshot_api_client',
    DB_TABLE_SNAPSHOT = 'web_snapshot_api_snapshot',
    DB_TABLE_SNAPSHOT_URL = 'web_snapshot_api_snapshot_url', // if original URL had redirects, this is target URL from where the snapshot taken. Filled only if target URL differs from original.

    // MODULES
    // -------
    express = require('express'),
    bodyParser = require('body-parser'),
    puppeteer = require('puppeteer'),
    dotenv = require('dotenv').config(),
    path = require('path'), // for path.join(), using system-specific path delimiter
    fs = require('fs'), // for mkdir()
    { v4: uuidv4 } = require('uuid'),
    mysql = require('mysql2'), // prefer callback style, not /promise

    app = express(),
    port = 3000,

    // common error reasons
    ERR_DB_ERROR = 'Temporary db error.',

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
    // The only response in text/html format. Others are JSONs.
    methodNotAllowed = x => res.set('Content-Type', 'text/html')
                                .status(405)
                                .send('<h1>405 Method Not Allowed</h1>'),

    // same as parseFloat, but returns 0 if parseFloat returns non-numerical value
    fl0at = (v, def) => isNaN(v = parseFloat(v))
                            ? (undefined !== def ? def : 0) // "" is good value too. Don't replace with 0 if "" set.
                            : v,

    storageDirId = fileId => (Math.floor(fileId / 1000) * 1000).toString(),
    getStorageDir = fileId => path.join(process.env.STORAGE_DIR || path.join(__dirname, DEF_STORAGE_DIR),
                                        storageDirId(fileId)),

    getIp = req => { // Alternatively try module 'request-ip'. But this should work already. Also we have X-Real-IP header in Nginx config.
        const forwardedIps = req.headers['x-forwarded-for'];
        return forwardedIps && (-1 !== forwardedIps.indexOf(',')) ? forwardedIps.split(',')[0] : req.socket.remoteAddress;
    },

    // on Success: .then(clientId, dbConnection)
    // on Failure: .catch(httpStatus, errorReason)
    authenticate = (accessKey, needDb) => { // needDb = don't release db connection if TRUE.
        return new Promise((resolve, reject) => {
            const ERR_INVALID_ACCESS_KEY = 'Invalid access key. You have limited number of attempts before your IP will be banned.',

                dbError = err => {
                    console.error('MySQL error during authentication.', err);
                    reject([500, "Temporarily can't validate access key."]); // + err.message? But better to not disclose exact error reasons for security purposes.
                };

            // Check Authorzation first. We require explicit "Bearer" scheme, in compliance with https://www.rfc-editor.org/rfc/rfc6750
            accessKey = accessKey.split(' ', 2); // [Authentication Scheme] [Access Key]. We support Bearer scheme only. Access Key must contain valid base64 characters only.

            if (('Bearer' !== accessKey[0]) // we don't want to check db if key length is less than allowed minimum. And yes, even scheme name ("Bearer") is case sensitive here. Consider this as part of the token :)
                    || !(accessKey = accessKey[1])
                    || !/^[A-Za-z\d+/]+={0,2}$/.test(accessKey)) { // Are characters valid for base64 encoding? (No bad characters? We don't want to check them in DB + keys with non-base64 encoding characters are not really Bearer-compliant.)

                return reject([403, 'Authorization required by Bearer scheme.']);
            }

            if (accessKey.length < MIN_ACCESS_KEY_LEN) { // we don't want to check db if key length is less than allowed minimum.
                // Although we warn user that numer of request attempts are limited, we don't want to log this request in DB, if length is less than required. Just ignore this.
                return reject([403, ERR_INVALID_ACCESS_KEY]); // it's obviously invalid. But we return the same error after validation, if the key not found.
            }

            dbPool.getConnection((err, db) => {
                if (err) return dbError(err);

                // Use unprepeared query here. It will not be reused within current connection anyway. And accessKey doesn't contain characters that may allow SQL injection.
                db.query(`SELECT id FROM ${DB_TABLE_CLIENT} WHERE token="${accessKey}" AND active=1`, // safe. We sanitized bad characters above.
                        (err, rows, rowsLen) => {

                    if (!(rowsLen = rows.length) || !needDb || err) { // cases when we need to release db connection
                        db.release();
                        if (err) return dbError(err);

                        if (!rowsLen) { // non-fatal error, just correct accessKey not found
                            console.error('Invalid access key', accessKey); // TODO: set up the limit!
                            return reject([403, ERR_INVALID_ACCESS_KEY]);
                        }
                    }

                    resolve([rows[0].id, db]);
                });
            });
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
        get: (string) Default is 'url', to return the snapshot URL.
                * Other methods not implemented on 2024-02-14. TODO: get either URL to the filename with snapshot (url) OR base64-encoded binary data of the image (base64).
                * Or somehow else protect the file from non-authorized downloading (maybe require additional request headers).

    This server holds the snapshot in its storage. The following parameters allow to manage snapshot in storage.
        overwrite: (int) Default is 0 (false). Any non-0 value overwrites LAST existing (previous) snapshot of an URL, even if it's inactive (removed before).
                    0 (default) = create a new record, leaving existing snapshot(s) in archive.
                    1 = overwrite last snapshot record, and store the file under existing name on server. (However if image format changes, it returns filename with requested extension, so de-facto file being renamed in storage.)
                    2 = overwrite last snapshot record, but rename the file, return the new file name (as URL) in 'snapshot' field.
            * NOTE:  it creates new record anyway if no existing entries are found.

    ----- TODO -----

        valid_time: (int) in seconds. Default is 7 * 24 * 60 * 60 (eg 7 days = 604800 seconds).
                    Timeout in seconds of validity of existing snapshot. Set to 0 always retreive fresh screenshot, or 3600 (60*60 seconds) to retreive fresh screenshot not often than once per hour.

        expire: (int OR string) with the Unix timestamp OR DATE in YYYY-MM-DD HH:MM:SS(+TZ) format. Default timzone is UTC (GMT+0).
                    Timestamp when the file should be expired and deleted from server storage.
                    NOTE: garbage collector (separate script) removes images by schedule defined in crontab (usually daily).

    Response:
        Successful response is HTTP code 201 (snapshot created).
            Returns:
                id: unique ID of snapshot
                url: final target URL after browser redirects. In most cases it not differs from the request url.
                snapshot: URL to the snapshot image. Can be downloaded from this location.

        Failure -- 4XX or 5XX, depending on the cause of the error (4XX are client side, 5XX are server side errors).
*/
app.route('/snapshot')
    .post((req, res) => {
        const data = req.body,
            url = data.url;

        // We don't want to connect to mySQL if URL not provided. Certanily bad request.
        if (('string' != typeof url) || (url.length < MIN_URL_LENGTH)) {
            return res.status(400).json({ error: "'url' is required." });
        }

        authenticate(req.headers['authorization'])
            .then(clientId => {
                clientId = clientId[0];

                let width = Math.abs(fl0at(data.width, DEF_PAGE_WIDTH)),
                    height = Math.abs(fl0at(data.height, DEF_PAGE_HEIGHT)),
                    format = data.format;

                if (width > MAX_PAGE_WIDTH) width = MAX_PAGE_WIDTH;
                if (height > MAX_PAGE_HEIGHT) height = MAX_PAGE_HEIGHT;
                if (format) {
                    format.toLowerCase().replace(/[^a-z\d]/g, ''); // strip all non-latin and non-digit characters. But mostly it used to trim possible leading dot.
                    if ('jpeg' === format) format = 'jpg';
                    if (-1 === SUPPORTED_IMAGE_FORMATS.indexOf(format)) {
                        return res.status(400).json({ error: `Unsupported image format. Request either: ${SUPPORTED_IMAGE_FORMATS.join(', ')}.` });
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
                                console.log('Browsing to', url);
                                page.goto(url, {
                                    // wait for content to load
                                    waitUntil: 'networkidle0' //'domcontentloaded',
                                    //timeout: 0
                                }).then(() => {
                                    console.log('Successful navigation to', url);

                                    dbPool.getConnection((err, db) => {
                                        const releaseMem = () => {
                                                browser.close();
                                                if (db) db.release();
                                            },

                                            dbError = err => {
                                                releaseMem();
                                                console.error('MySQL error.', err);
                                                res.status(500).json({ error: 'DB error.' });
                                            },

                                            storageError = err => {
                                                releaseMem();
                                                console.error('Disk error.', err);
                                                res.status(507).json({ error: 'Insufficient storage' });
                                            };

                                        if (err) return dbError(err);

                                        const requestUrl = url.slice(0, MAX_URL_LENGTH),
                                            targetUrl = page.url().slice(0, MAX_URL_LENGTH), // this is final URL after all possible redirections

                                            makeSnapshotRecord = (recId, newSnapshotName, fileNameToUnlink, isOverwrite) => { // recId is (int).
                                                const targetDir = getStorageDir(recId),
                                                    newFn = path.join(targetDir, newSnapshotName + '.' + format),

                                                    response = {
                                                            id: recId,
                                                            url: targetUrl,
                                                            snapshot: newFn,
                                                        },

                                                    takeSnapshot = () => {
                                                        // take a screenshot only after record will be inserted into db.
                                                        page.screenshot({
                                                            path: newFn,
                                                            fullPage: !!data.fullpage // AK: !! used for security, to get only boolean value
                                                        }).then(() => {
                                                            db.query(`UPDATE ${DB_TABLE_SNAPSHOT} SET ${
                                                                            isOverwrite // if we reusing existing record, dimensions and format can be changed.
                                                                                ? `width=${width}, height=${height}, format='${format}', ${
                                                                                        1 === isOverwrite
                                                                                            ? '' // same filename when overwrite. Extension can be changed though
                                                                                            : `snapshot='${newSnapshotName}', ` // all snapshot names are SQL-safe
                                                                                        }`
                                                                                : ''
                                                                        }active=1 WHERE id=${recId}`, // safe, int value
                                                                    (err, rows) => {

                                                                if (err) return dbError(err);

                                                                if (fileNameToUnlink) {
                                                                    fs.unlink(path.join(targetDir, fileNameToUnlink), err => {}); // we don't care of result here. It's not critical, but should succeed.
                                                                }

                                                                console.log('SNAPSHOT CREATED');
                                                                res.status(201).json(response);
                                                            });
                                                        }).catch(errorReason => {
                                                            console.error('SNAPSHOT FAILURE', errorReason);
                                                            response.error = 'Insufficient Storage';
                                                            res.status(507).json(response);
                                                        }).finally(() => {
                                                            releaseMem();
                                                        });
                                                    };

                                                if (targetUrl !== url) { // we don't care about result or errors here. Just execute and forget.
                                                    db.query(`INSERT INTO ${DB_TABLE_SNAPSHOT_URL} (id, url) VALUES(${recId}, '${mysql.escapeId(targetUrl)}')` +
                                                        (isOverwrite ? ' ON DUPLICATE KEY UPDATE url=VALUES(url)' : ''));
                                                }

                                                // check whether target directory exists. If not -- recursively create the directory structure.
                                                fs.access(targetDir, err => {
                                                    if (err) { // directory not exists yet
                                                        fs.mkdir(targetDir, { recursive: true }, err => { // trying to create...
                                                            if (err) {
                                                                storageError(err); // `Can't create new directory ${targetDir}`
                                                            }else {
                                                                takeSnapshot();
                                                            }
                                                        });
                                                    }else {
                                                        takeSnapshot();
                                                    }
                                                });
                                            },

                                            makeNewSnapshot = () => {
                                                const snapshotName = uuidv4(); // ATTN! All snapshot names should be SQL-safe, so can be used in queries w/o escaping! TODO: Think about additional obfuscation

                                                // Inserting INACTIVE record, which will be activated once snapshot will be successfully completed.
                                                db.query(`INSERT INTO ${DB_TABLE_SNAPSHOT} SET client=?, url=?, width=${width}, height=${height}, format='${format}', snapshot='${snapshotName}', time=CURRENT_TIMESTAMP, ip=?,active=0`,
                                                        [clientId, requestUrl, getIp(req)],
                                                        (err, rows) => {
                                                            if (err) return dbError(err);
                                                            makeSnapshotRecord(rows.insertId, snapshotName); // no old filename, no overwrite, it's always new record
                                                        });
                                            },

                                            // reuse last record for requested URL or create a new one?
                                            isOverwrite = parseInt(data.overwrite) || 0;

                                        if (isOverwrite) {
                                            // Find existing record and pick the last one (even if it's inactive). If there is none -- insert a new record.
                                            db.query(`SELECT id, format, snapshot FROM ${DB_TABLE_SNAPSHOT} WHERE client='${clientId}' AND url=? ORDER BY id DESC LIMIT 1`,
                                                    [requestUrl],
                                                    (err, rows) => {
                                                        if (err) return dbError(err);

                                                        if (rows.length) { // reuse last existing record
                                                            makeSnapshotRecord(rows[0].id,
                                                                    1 === isOverwrite ? rows[0].snapshot : uuidv4(), // new snapshot name. ATTN! All snapshot names should be SQL-safe, so can be used in queries w/o escaping!
                                                                    1 === isOverwrite && rows[0].format === format ? null : rows[0].snapshot + '.' + rows[0].format, // existing filename (full filename with extension)
                                                                    isOverwrite);

                                                        }else { // no record? create a new one
                                                            makeNewSnapshot();
                                                        }
                                                    });
                                        }else {
                                            makeNewSnapshot();
                                        }
                                    });

                                }) // page.goto() failure
                                .catch(errorReason => {
                                    browser.close();

                                    console.error('NAVIGATION FAILURE', errorReason);
                                    res.status(500).json({ error: 'Failed to load URL.' });
                                });
                            }) // browser.newPage() failure
                            .catch(errorReason => {
                                browser.close();

                                console.error('Failed to open new page', errorReason);
                                res.status(500).json({ error: 'Failed to open new page.' });
                            })
                        ) // puppeteer.launch() failure
                        .catch(errorReason => {
                            console.error('Failed to launch browser', errorReason);
                            res.status(500).json({ error: 'Failed to launch browser.' });
                        });
            }) // unsuccessful authentication
            .catch(err => {
                res.status(err[0]).json({ error: err[1] });
            });
    }).all(x => methodNotAllowed);

/*
    Accepted parameters:
        url: (string) Optional. If specified, returned list is all snapshots taken from this URL.

    Response: 200 OK on success, 4XX or 5XX on failure.
*/
app.route('/list')
    .post((req, res) => {
        authenticate(req.headers['authorization'], true)
            .then((clientId, db) => {
                [clientId, db] = clientId;

                const data = req.body,
                    url = data.url;

                db.query(`SELECT id, ${url ? 'url, ' : ''}snapshot, time
                        FROM ${DB_TABLE_SNAPSHOT}
                        WHERE client=${clientId} AND active=1 AND (expire=0 OR expire<CURRENT_TIMESTAMP)`, (err, rows) => { // safe query
                    if (err) {
                        db.release();
                        return req.status(500).json({ error: ERR_DB_ERROR });
                    }

                    const list = [];
                    for (let row of rows) {
                        console.log(row);
                        list.push(row);
                    }

                    res.status(200).json({ list });
                });
            }) // unsuccessful authentication
            .catch(err => {
                res.status(err[0]).json({ error: err[1] });
            });

    }).all(x => methodNotAllowed);

/*
    Accepted parameters:
        snapshot unique id: (string) Required.

    Response: 200 OK on success, 4XX or 5XX on failure.

    IMPORTANT NOTE!
        * Clients identified by accessKey can delete only their own snapshots. Other snapshots, created by another clients can't be deleted, server returns 400 Bad Request.
*/

app.route('/remove')
    .post((req, res) => {
        const data = req.body,
            snapshotId = parseInt(data.id) || 0;

        if (!snapshotId) {
            return res.status(400).json({ error: "'id' is required." });
        }

        authenticate(req.headers['authorization'], true)
            .then((clientId, db) => {
                [clientId, db] = clientId;

                // Get the snapshot filename + check, whether it belongs to requesting client.
                db.query(`SELECT snapshot, format, client, active FROM ${DB_TABLE_SNAPSHOT} WHERE id=${snapshotId} AND client=${clientId}`, (err, rows) => { // safe query
                    if (err) {
                        db.release();
                        return req.status(500).json({ error: ERR_DB_ERROR });
                    }

                    if (!rows.length) {
                        db.release();
                        return res.status(400).json({ error: "Snapshot doesn't exists." }); // ...or created by another client
                    }

                    const fn = path.join(getStorageDir(snapshotId), rows[0].snapshot + '.' + rows[0].format);

                    if (!rows[0].active) {
                        db.release();
                        // try to unlink file anyway!
                        fs.unlink(fn, err => {}); // we don't care of result here. We almost sure that file was already deleted before and it returns 'ENOENT: no such file or directory'.
                        return res.status(400).json({ error: "Snapshot already deleted." });
                    }

                    // Update the db record
                    db.query(`UPDATE ${DB_TABLE_SNAPSHOT} SET active=0 WHERE id=${snapshotId}`, (err, rows) => { // safe query
                        db.release();

                        if (err) {
                            return req.status(500).json({ error: ERR_DB_ERROR });
                        }

                        fs.unlink(fn, err => {
                            if (err) {
                                // check, maybe we tried to delete unexisting file? Then it's okay. If file is not there, then it's not an error.
                                fs.access(fn, fs.constants.F_OK, (accessErr) => {
                                    if (!accessErr) { // if file still exists, then it can't be deleted. Return error.
                                        return res.status(500).json({ error: `Record deactivated in DB, but can't delete snapshot file from the storage. ${err.message}` });
                                    }

                                    // otherwise we don't care if file doesn't exists in storage.
                                    res.status(204).send(); // no content = OK.
                                });
                            }else {
                                res.status(204).send(); // no content = OK.
                            }
                        });
                    });
                });
            }) // unsuccessful authentication
            .catch(err => {
                res.status(err[0]).json({ error: err[1] });
            });

    }).all(x => methodNotAllowed);

// Start server
app.listen(port, () => {
    console.log(`${appName} running on port ${port}...`);
});
