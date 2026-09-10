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
