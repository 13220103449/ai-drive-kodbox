CREATE TABLE IF NOT EXISTS `plugin_ai_drive_agent` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agentID` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `userID` bigint(20) unsigned NOT NULL,
  `tokenHash` char(64) NOT NULL,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `lastUsedAt` int(11) unsigned NOT NULL DEFAULT 0,
  `createdAt` int(11) unsigned NOT NULL,
  `updatedAt` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agentID` (`agentID`),
  UNIQUE KEY `tokenHash` (`tokenHash`),
  KEY `userID` (`userID`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive Agent credentials';

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agentID` varchar(64) NOT NULL,
  `userID` bigint(20) unsigned NOT NULL,
  `action` varchar(80) NOT NULL,
  `result` varchar(32) NOT NULL,
  `detail` varchar(1000) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `createTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `agentID` (`agentID`),
  KEY `userID` (`userID`),
  KEY `action` (`action`),
  KEY `createTime` (`createTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive Agent audit';
