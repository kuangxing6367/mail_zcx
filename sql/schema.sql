-- =============================================================
--  ZC Mail Service 数据库结构
--  MySQL 5.7+ / 8.x  字符集 utf8mb4
--  表前缀 zc_  (可在 config.php 中修改，本文件为默认前缀)
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------
-- 1. 邮箱账号：多邮箱管理，每个账号自带 SMTP 主机/端口/加密方式
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_mailboxes` (
  `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(64)      NOT NULL DEFAULT ''            COMMENT '备注名，如 通知专用',
  `email`        VARCHAR(190)     NOT NULL                       COMMENT '发件邮箱地址',
  `display_name` VARCHAR(128)     NOT NULL DEFAULT ''            COMMENT '发件人显示名',
  `smtp_host`    VARCHAR(190)     NOT NULL                       COMMENT 'SMTP 主机或 IP',
  `smtp_port`    SMALLINT UNSIGNED NOT NULL DEFAULT 465          COMMENT '端口 465/587/25',
  `smtp_secure`  ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl' COMMENT 'ssl=465直连, tls=587 STARTTLS, none=明文',
  `smtp_user`    VARCHAR(190)     NOT NULL DEFAULT ''            COMMENT 'SMTP 用户名，留空则用 email',
  `smtp_pass`    VARCHAR(512)     NOT NULL DEFAULT ''            COMMENT 'SMTP 密码/授权码，加密存储',
  `status`       TINYINT          NOT NULL DEFAULT 1             COMMENT '1 启用 / 0 禁用',
  `weight`       INT              NOT NULL DEFAULT 1             COMMENT '权重，weight 策略下越大越优先',
  `daily_limit`  INT              NOT NULL DEFAULT 0             COMMENT '每日发件上限，0 = 不限',
  `sent_today`   INT              NOT NULL DEFAULT 0             COMMENT '今日已发件数',
  `quota_date`   DATE                      DEFAULT NULL          COMMENT 'sent_today 所属日期',
  `sent_total`   BIGINT           NOT NULL DEFAULT 0             COMMENT '累计成功发件数（智能选箱依据）',
  `fail_total`   BIGINT           NOT NULL DEFAULT 0             COMMENT '累计失败次数',
  `last_used_at` DATETIME                  DEFAULT NULL,
  `last_error`   TEXT                                            COMMENT '最近一次错误',
  `remark`       VARCHAR(255)     NOT NULL DEFAULT '',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status_sent` (`status`, `sent_total`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发件邮箱账号';

-- -------------------------------------------------------------
-- 2. 邮件模板：支持 {{变量}} 渲染，HTML / 纯文本双版本
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_templates` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(64)  NOT NULL                       COMMENT '模板标识，调用时用',
  `name`       VARCHAR(128) NOT NULL DEFAULT ''            COMMENT '模板名称',
  `subject`    VARCHAR(255) NOT NULL DEFAULT ''            COMMENT '主题模板，支持 {{var}}',
  `body_html`  MEDIUMTEXT                                  COMMENT 'HTML 正文模板',
  `body_text`  MEDIUMTEXT                                  COMMENT '纯文本正文模板',
  `variables`  TEXT                                        COMMENT '变量说明 JSON，如 {"code":"验证码"}',
  `status`     TINYINT      NOT NULL DEFAULT 1             COMMENT '1 启用 / 0 禁用',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件模板';

-- -------------------------------------------------------------
-- 3. 发送日志：每次发信一条，含状态/SMTP 响应/耗时
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_mail_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`    VARCHAR(64)     NOT NULL DEFAULT ''      COMMENT '调用方追踪 ID',
  `mailbox_id`    INT UNSIGNED             DEFAULT NULL,
  `from_email`    VARCHAR(190)    NOT NULL DEFAULT '',
  `to_email`      VARCHAR(1000)   NOT NULL DEFAULT ''      COMMENT '收件人，逗号分隔',
  `cc_email`      VARCHAR(1000)   NOT NULL DEFAULT '',
  `bcc_email`     VARCHAR(1000)   NOT NULL DEFAULT '',
  `subject`       VARCHAR(500)    NOT NULL DEFAULT '',
  `template_code` VARCHAR(64)     NOT NULL DEFAULT '',
  `status`        ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  `smtp_code`     VARCHAR(16)     NOT NULL DEFAULT ''      COMMENT 'SMTP 最终响应码，如 250',
  `smtp_message`  TEXT                                     COMMENT 'SMTP 最终响应文本',
  `error`         TEXT                                     COMMENT '失败原因',
  `duration_ms`   INT             NOT NULL DEFAULT 0,
  `source_ip`     VARCHAR(64)     NOT NULL DEFAULT '',
  `user_agent`    VARCHAR(255)    NOT NULL DEFAULT '',
  `payload`       MEDIUMTEXT                               COMMENT '原始请求体 JSON',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`       DATETIME                 DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status`  (`status`),
  KEY `idx_mailbox` (`mailbox_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮件发送日志';

-- -------------------------------------------------------------
-- 4. 出站 Webhook 配置：发信结果回调
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_webhooks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(128) NOT NULL DEFAULT '',
  `url`        VARCHAR(500) NOT NULL                       COMMENT '回调地址',
  `secret`     VARCHAR(255) NOT NULL DEFAULT ''            COMMENT 'HMAC-SHA256 签名密钥',
  `events`     VARCHAR(255) NOT NULL DEFAULT 'success,failed' COMMENT '触发事件，逗号分隔',
  `headers`    TEXT                                        COMMENT '附加请求头 JSON',
  `status`     TINYINT      NOT NULL DEFAULT 1,
  `retry`      TINYINT      NOT NULL DEFAULT 2,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='出站 Webhook 配置';

-- -------------------------------------------------------------
-- 5. 出站投递记录
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_webhook_deliveries` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id`   INT UNSIGNED             DEFAULT NULL,
  `log_id`       BIGINT UNSIGNED          DEFAULT NULL,
  `event`        VARCHAR(32)     NOT NULL DEFAULT '',
  `url`          VARCHAR(500)    NOT NULL DEFAULT '',
  `request_body` MEDIUMTEXT,
  `http_code`    INT             NOT NULL DEFAULT 0,
  `response`     TEXT,
  `status`       ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
  `attempts`     INT             NOT NULL DEFAULT 0,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log`     (`log_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook 投递记录';

-- -------------------------------------------------------------
-- 6. 入站事件：接收外部系统推送
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_inbound_events` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source`     VARCHAR(64)  NOT NULL DEFAULT ''            COMMENT '来源标识',
  `event`      VARCHAR(64)  NOT NULL DEFAULT ''            COMMENT '事件类型',
  `payload`    MEDIUMTEXT,
  `source_ip`  VARCHAR(64)  NOT NULL DEFAULT '',
  `signature`  VARCHAR(255) NOT NULL DEFAULT '',
  `verified`   TINYINT      NOT NULL DEFAULT 0             COMMENT '签名是否通过',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='入站 Webhook 事件';

-- -------------------------------------------------------------
-- 7. API Key 鉴权
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(128) NOT NULL DEFAULT '',
  `api_key`      VARCHAR(64)  NOT NULL                     COMMENT '仅存脱敏展示串，不参与鉴权',
  `api_key_hash` CHAR(64)              DEFAULT NULL        COMMENT 'sha256(api_key)；老数据首次鉴权时自动回填',
  `scopes`       VARCHAR(255) NOT NULL DEFAULT '*'         COMMENT '逗号分隔的权限：send,logs,mailboxes,templates,webhooks,keys；*=全部',
  `api_secret`   VARCHAR(128) NOT NULL DEFAULT ''          COMMENT '保留字段，当前鉴权不使用',
  `status`       TINYINT      NOT NULL DEFAULT 1,
  `allow_ips`    VARCHAR(1000) NOT NULL DEFAULT ''         COMMENT 'IP 白名单，逗号分隔，空=不限；支持通配与 CIDR',
  `rate_limit`   INT          NOT NULL DEFAULT 0           COMMENT '每分钟请求上限，0=不限',
  `expires_at`   DATETIME              DEFAULT NULL,
  `last_used_at` DATETIME              DEFAULT NULL,
  `use_count`    BIGINT       NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hash` (`api_key_hash`),
  KEY `idx_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API 密钥';

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
--  初始数据
-- =============================================================

-- 默认 API Key（请首次部署后立即在后台轮换）
-- 默认密钥：api_key 保留明文仅用于首次安装时读取（首次鉴权后会自动替换为脱敏串）
SET @zc_seed_key := CONCAT('zc_', REPLACE(UUID(), '-', ''));
INSERT INTO `zc_api_keys` (`name`, `api_key`, `api_key_hash`, `scopes`, `api_secret`, `status`, `rate_limit`)
SELECT '默认密钥', @zc_seed_key, SHA2(@zc_seed_key, 256), '*', '', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM `zc_api_keys`);

-- 示例模板：验证码
INSERT INTO `zc_templates` (`code`, `name`, `subject`, `body_html`, `body_text`, `variables`, `status`)
SELECT 'verify_code', '验证码通知', '【ZC】您的验证码是 {{code}}',
       '<div style="font-family:sans-serif;padding:24px"><h2>验证码</h2><p>您的验证码为：<b style="font-size:24px;color:#2563eb">{{code}}</b></p><p>有效期 {{expire}} 分钟，请勿泄露。</p></div>',
       '您的验证码为：{{code}}，有效期 {{expire}} 分钟。',
       '{"code":"验证码","expire":"有效期(分钟)"}', 1
WHERE NOT EXISTS (SELECT 1 FROM `zc_templates` WHERE `code` = 'verify_code');

-- -------------------------------------------------------------
-- 8. 发信队列作业：根治同步阻塞，API 只写库立即返回 202，
--    cli/worker.php 后台认领并执行 SMTP 投递（含重试）
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zc_send_jobs` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_id`          BIGINT UNSIGNED NOT NULL                COMMENT '关联的发送日志 ID',
  `mailbox_id`      INT UNSIGNED     NOT NULL DEFAULT 0     COMMENT '发件邮箱 ID（prepare 阶段已选定）',
  `status`          ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `payload`         MEDIUMTEXT       NOT NULL                COMMENT '作业载荷 JSON：MIME 原文/收件人/上下文/原始 input',
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0     COMMENT '已投递次数',
  `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 3     COMMENT '最大投递次数（含首次）',
  `last_error`      TEXT,
  `smtp_code`       VARCHAR(16)      NOT NULL DEFAULT '',
  `smtp_message`    TEXT,
  `duration_ms`     INT              NOT NULL DEFAULT 0,
  `next_attempt_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '可重试的最早时间',
  `reserved_at`     DATETIME                  DEFAULT NULL   COMMENT '被 worker 认领时间',
  `reserved_by`     VARCHAR(64)      NOT NULL DEFAULT ''    COMMENT 'worker 标识（host:pid）',
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at`     DATETIME                  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_next` (`status`, `next_attempt_at`),
  KEY `idx_log` (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发信队列作业';
