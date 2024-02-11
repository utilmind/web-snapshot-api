# web-snapshot-api
 Web Snapshot Maker API

NodeJS + Express framework

## Content
 * `distr` workable REST API server itself.
 * `test` PHP script for testing an API.

## API Rules
 * Accepts and returns in JSON format only (Content-type: application/json).
 * 

## Valid API parameters
 * `url`: (string) Required parameter.
 * `width`: (float) Default is DEF_PAGE_WIDTH.
 * `height`: (float) Default is DEF_PAGE_HEIGHT.
 * `full_page`: (bool) Default is true/1. Set to false/0 to disable.
 * `format`: (string) Default is DEF_IMAGE_FORMAT. Can be eighter `PNG`, `JPG` or `WEBP`. Case insensitive. Filename created with lowercase extension.
