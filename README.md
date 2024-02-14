# web-snapshot-api
 Web Snapshot Maker API

NodeJS + Express framework + Puppeteer

## Disclaimer
This is my first public Open Source project in NodeJS. Please don't judge harshly and welcome to contribute.

## Installation (Linux)
Make sure that you have installed Chromium browser and "chrome" NPM module.
 * `sudo apt get chromium`
 * `sudo npm install chrome`

## Content
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
