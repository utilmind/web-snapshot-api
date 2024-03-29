# web-snapshot-api
 Web Snapshot Maker API.
  * Not just another snapshot maker, but the full featured REST API which receiving an URLs from another apps and returns an URL to produced `/snapshot`.
  * Additionally it holds produced snapshots in the storage and gives an access to entire `/list`, until the expiration of stored files OR until the outdated snapshots will be deleted with `/remove` command.

The Git contains the client app example in PHP (v7+), in `client-app-example` directory.

Tools:
  * NodeJS + Express framework + Puppeteer.

## Disclaimer
This is my first public Open Source project in NodeJS. Please don't judge harshly and welcome to contribute.

## Installation (Linux)
Make sure that you have installed Chromium browser and "chrome" NPM module.
 * `sudo apt get chromium`
 * `sudo npm install chrome`

## Content
 * `_db` SQL queries to create mySQL database tables. (MySQL used to index the cached snapshots, keep the log, do client authentication with acces keys stored in db.)
 * `distr` workable REST API server itself.
 * `test` PHP script for testing an API.

## API Rules
 * Accepts and returns in JSON format only (Content-type: application/json).

## Valid API parameters
 * `url`: (string) Required parameter.
 * `width`: (float) Default is DEF_PAGE_WIDTH.
 * `height`: (float) Default is DEF_PAGE_HEIGHT.
 * `full_page`: (bool) Default is true/1. Set to false/0 to disable.
 * `format`: (string) Default is DEF_IMAGE_FORMAT. Can be eighter `PNG`, `JPG` or `WEBP`. Case insensitive. Filename created with lowercase extension.

## Returns
 * Success: HTTP status 201 (snapshot created). JSON { url: returns original URL, snapshot: filename }.
 * Failure: HTTP status 400 (or another 4XX/5XX). JSON { error: reason }.

## Similar projects
Atually not looked into them, here is the top results from Google search by `github web snapshot` keywords...
 * https://github.com/sindresorhus/capture-website
 * https://github.com/topics/website-screenshot-capturer
 * https://github.com/maaaaz/webscreenshot

etc... I'm sure there are many similar project out there, but my original goal was to create my own implementation, mainly for the purpose of self-education.
