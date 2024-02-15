--
-- Table structure for table `web_snapshot_api_client`
--
/* DROP TABLE IF EXISTS `web_snapshot_api_client`; */
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `web_snapshot_api_client` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` char(64) NOT NULL DEFAULT '',
  `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE KEY `key` (`key`) USING BTREE,
  KEY `active` (`active`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=ascii ROW_FORMAT=FIXED;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `web_snapshot_api_request_log`
--
/* DROP TABLE IF EXISTS `web_snapshot_api_request_log`;*/
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `web_snapshot_api_request_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'points to _client.id',
  `url` char(255) NOT NULL DEFAULT '' COMMENT 'ASCII only',
  `width` float unsigned NOT NULL,
  `height` float unsigned NOT NULL,
  `format` char(4) NOT NULL DEFAULT '' COMMENT 'Lowercase only, please.\r\npng, jpg, webp, etc',
  `snapshot` char(128) NOT NULL DEFAULT '' COMMENT 'Result file name',
  `time` datetime NOT NULL DEFAULT current_timestamp(),
  `ip` char(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `client` (`client`) USING BTREE
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=ascii ROW_FORMAT=FIXED;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `web_snapshot_api_ban`
--
/* DROP TABLE IF EXISTS `web_snapshot_api_ban`; */
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `web_snapshot_api_ban` (
  `ip` char(45) NOT NULL DEFAULT '',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'Failed attempts',
  `time` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip`)
) ENGINE=MyISAM DEFAULT CHARSET=ascii PACK_KEYS=0;
/*!40101 SET character_set_client = @saved_cs_client */;
