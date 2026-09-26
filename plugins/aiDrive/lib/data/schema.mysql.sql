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

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_token` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agentID` varchar(64) NOT NULL,
  `tokenHash` char(64) NOT NULL,
  `expiresAt` int(11) unsigned NOT NULL DEFAULT 0,
  `lastUsedAt` int(11) unsigned NOT NULL DEFAULT 0,
  `createdAt` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `tokenHash` (`tokenHash`), KEY `agentID` (`agentID`), KEY `expiresAt` (`expiresAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive overlapping Agent tokens';

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_version` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agentID` varchar(64) NOT NULL, `userID` bigint(20) unsigned NOT NULL,
  `space` varchar(32) NOT NULL, `path` varchar(1000) NOT NULL, `operation` varchar(32) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0, `sha256` char(64) NOT NULL DEFAULT '',
  `blobPath` varchar(1000) NOT NULL DEFAULT '', `createTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `agentPath` (`agentID`,`space`,`path`(255)), KEY `createTime` (`createTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive file versions';

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_trash` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agentID` varchar(64) NOT NULL, `userID` bigint(20) unsigned NOT NULL,
  `space` varchar(32) NOT NULL, `originalPath` varchar(1000) NOT NULL,
  `storagePath` varchar(1000) NOT NULL, `itemType` varchar(16) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0, `deletedAt` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `agentDeleted` (`agentID`,`deletedAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive soft-deleted files';

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_update` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fromVersion` varchar(64) NOT NULL, `toVersion` varchar(64) NOT NULL, `status` varchar(32) NOT NULL,
  `backupPath` varchar(1000) NOT NULL DEFAULT '', `detail` varchar(1000) NOT NULL DEFAULT '',
  `createTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`), KEY `createTime` (`createTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive update history';

CREATE TABLE IF NOT EXISTS `plugin_ai_drive_request` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `agentID` varchar(64) NOT NULL,
  `requestID` varchar(128) NOT NULL, `action` varchar(80) NOT NULL, `success` tinyint(3) unsigned NOT NULL,
  `response` mediumtext NOT NULL, `createTime` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `agentRequest` (`agentID`,`requestID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI Drive idempotent request results';
