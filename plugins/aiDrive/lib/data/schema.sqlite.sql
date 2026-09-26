CREATE TABLE IF NOT EXISTS "plugin_ai_drive_agent" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL UNIQUE,
  "name" varchar(120) NOT NULL,
  "userID" integer NOT NULL,
  "tokenHash" char(64) NOT NULL UNIQUE,
  "status" integer NOT NULL DEFAULT 1,
  "lastUsedAt" integer NOT NULL DEFAULT 0,
  "createdAt" integer NOT NULL,
  "updatedAt" integer NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_agent_user" ON "plugin_ai_drive_agent" ("userID");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_agent_status" ON "plugin_ai_drive_agent" ("status");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_audit" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL,
  "userID" integer NOT NULL,
  "action" varchar(80) NOT NULL,
  "result" varchar(32) NOT NULL,
  "detail" varchar(1000) NOT NULL DEFAULT '',
  "ip" varchar(64) NOT NULL DEFAULT '',
  "createTime" integer NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_audit_agent" ON "plugin_ai_drive_audit" ("agentID");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_audit_user" ON "plugin_ai_drive_audit" ("userID");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_audit_action" ON "plugin_ai_drive_audit" ("action");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_audit_time" ON "plugin_ai_drive_audit" ("createTime");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_token" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL,
  "tokenHash" char(64) NOT NULL UNIQUE,
  "expiresAt" integer NOT NULL DEFAULT 0,
  "lastUsedAt" integer NOT NULL DEFAULT 0,
  "createdAt" integer NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_token_agent" ON "plugin_ai_drive_token" ("agentID");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_token_expiry" ON "plugin_ai_drive_token" ("expiresAt");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_version" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL,
  "userID" integer NOT NULL,
  "space" varchar(32) NOT NULL,
  "path" varchar(1000) NOT NULL,
  "operation" varchar(32) NOT NULL,
  "size" integer NOT NULL DEFAULT 0,
  "sha256" char(64) NOT NULL DEFAULT '',
  "blobPath" varchar(1000) NOT NULL DEFAULT '',
  "createTime" integer NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_version_path" ON "plugin_ai_drive_version" ("agentID","space","path");
CREATE INDEX IF NOT EXISTS "idx_ai_drive_version_time" ON "plugin_ai_drive_version" ("createTime");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_trash" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL,
  "userID" INTEGER NOT NULL,
  "space" varchar(32) NOT NULL,
  "originalPath" varchar(1000) NOT NULL,
  "storagePath" varchar(1000) NOT NULL,
  "itemType" varchar(16) NOT NULL,
  "size" INTEGER NOT NULL DEFAULT 0,
  "deletedAt" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_trash_agent" ON "plugin_ai_drive_trash" ("agentID","deletedAt");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_update" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "fromVersion" varchar(64) NOT NULL,
  "toVersion" varchar(64) NOT NULL,
  "status" varchar(32) NOT NULL,
  "backupPath" varchar(1000) NOT NULL DEFAULT '',
  "detail" varchar(1000) NOT NULL DEFAULT '',
  "createTime" integer NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_ai_drive_update_time" ON "plugin_ai_drive_update" ("createTime");

CREATE TABLE IF NOT EXISTS "plugin_ai_drive_request" (
  "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
  "agentID" varchar(64) NOT NULL,
  "requestID" varchar(128) NOT NULL,
  "action" varchar(80) NOT NULL,
  "success" integer NOT NULL,
  "response" text NOT NULL,
  "createTime" integer NOT NULL,
  UNIQUE ("agentID","requestID")
);
