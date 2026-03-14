[English](README.md) | **中文**

# WHMCS OpenOAuth — OAuth2 登录模块

适用于 WHMCS 的 OAuth2 第三方登录插件。支持对接任意标准 OAuth2 服务器，实现客户端登录/注册、账户绑定/解绑、管理后台 OAuth 登录。

**适用版本：** WHMCS 8.13.1（Twenty-One 客户端模板，Blend 管理后台模板）

## 功能特性

- 客户端登录页显示 OAuth 登录按钮
- 新用户自动注册（可配置）
- 通过邮箱自动匹配并绑定已有 WHMCS 账户
- 个人资料页手动绑定/解绑 OAuth 账户
- 管理后台 OAuth 登录（可配置，通过管理员邮箱匹配）
- 所有 OAuth 端点 URL 均可在后台配置
- 全流程 CSRF 防护（state 参数 + 解绑令牌）
- 后台管理已绑定账户列表

## 环境要求

- WHMCS 8.13.1
- PHP 8.1+
- cURL 扩展
- 一个实现以下端点的 OAuth2 服务器：
  - `GET /oauth/authorize` — 授权端点
  - `POST /oauth/token` — 令牌端点
  - `GET /oauth/userinfo` — 用户信息端点（返回 `sub`、`email`、`name`、`picture`）

## 安装步骤

1. 将 `nanako_oauth` 目录复制到 WHMCS 安装目录：

```
whmcs/modules/addons/nanako_oauth/
```

2. 在 WHMCS 后台进入 **配置 > 系统设置 > 附加模块**。

3. 找到 **OpenOAuth Login**，点击 **激活**。

4. 填写模块配置（见下方配置说明）。

5. 在你的 OAuth2 服务器上注册一个新客户端，回调地址设为：

```
https://你的WHMCS域名/modules/addons/nanako_oauth/callback.php
```

## 配置说明

| 字段 | 说明 | 默认值 |
|------|------|--------|
| OAuth Server URL | OAuth 服务器基础地址（如 `https://auth.example.com`） | — |
| Client ID | OAuth2 客户端 ID | — |
| Client Secret | OAuth2 客户端密钥 | — |
| Scopes | 请求的 OAuth2 权限范围 | `profile email phone` |
| Login Button Text | 登录按钮显示文字 | `使用 OAuth 账号登录` |
| Enable Auto Registration | 为新 OAuth 用户自动创建 WHMCS 账户 | 否 |
| Enable Admin OAuth Login | 在管理后台登录页显示 OAuth 登录按钮 | 否 |

## 目录结构

```
nanako_oauth/
├── nanako_oauth.php       # 模块配置、激活、停用、后台页面
├── hooks.php              # ClientAreaHeadOutput 和 AdminAreaHeadOutput 钩子
├── callback.php           # OAuth2 回调处理（所有操作）
├── lib/
│   └── OAuthClient.php    # OAuth2 HTTP 客户端（授权、令牌、用户信息）
└── templates/
    ├── login_button.tpl   # 登录按钮模板（参考）
    ├── bind.tpl           # 绑定/解绑模板（参考）
    └── admin.tpl          # 后台页面模板（参考）
```

## 工作原理

### 客户端登录流程

1. 用户在 WHMCS 登录页点击 OAuth 登录按钮。
2. 生成 CSRF state 令牌，将登录意图存入 session，重定向到 OAuth 授权端点。
3. 用户授权后，OAuth 服务器携带 `code` 和 `state` 参数回调。
4. 验证 state，用 code 换取 access_token，获取用户信息。
5. 登录匹配：
   - **OAuth UID 已绑定** — 直接登录对应 WHMCS 用户。
   - **邮箱匹配到已有用户** — 自动绑定并登录。
   - **未匹配 + 开启自动注册** — 创建新用户、绑定并登录。
   - **未匹配 + 未开启自动注册** — 显示错误信息。
6. 通过 WHMCS `CreateSsoToken` API 建立会话（一次性 SSO 链接）。

### 账户绑定

- 已登录用户在个人资料页（`clientarea.php?action=details`）可以绑定/解绑 OAuth 账户。
- 绑定操作发起相同的 OAuth 流程，意图标记为 `bind`。
- 解绑操作需要 CSRF 令牌验证。

### 管理后台登录

- 启用后，管理后台登录页出现 OAuth 登录按钮。
- 授权后通过邮箱匹配 `tbladmins` 表中的管理员。
- 仅匹配已有管理员，不会自动创建管理员账户。

## 数据库

模块激活时创建一张表：

**`mod_nanako_oauth_links`**

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT AUTO_INCREMENT | 主键 |
| client_id | INT UNSIGNED | WHMCS 用户 ID（带索引） |
| oauth_uid | VARCHAR(255) | OAuth 用户唯一标识（唯一索引） |
| email | VARCHAR(255) | OAuth 邮箱 |
| username | VARCHAR(255) | OAuth 显示名称 |
| avatar | VARCHAR(512) | OAuth 头像 URL |
| created_at | DATETIME | 创建时间 |
| updated_at | DATETIME | 更新时间 |

模块停用时删除该表。

## 安全措施

- **CSRF 防护** — 所有 OAuth 流程使用随机 `state` 参数存入 session 并通过 `hash_equals()` 验证。解绑操作使用独立 CSRF 令牌。
- **密钥不暴露** — Client Secret 仅存储在服务端 WHMCS 配置中，不会发送到浏览器。
- **强制 SSL** — 所有 OAuth HTTP 请求均使用 cURL 并开启 `CURLOPT_SSL_VERIFYPEER` 和 `CURLOPT_SSL_VERIFYHOST`。
- **XSS 防护** — 所有用户数据通过 DOM API（`createElement`/`textContent`）或 `json_encode()` 注入页面，不使用 `innerHTML` 拼接用户数据。
- **无调试信息泄露** — 错误详情仅记录到 WHMCS 活动日志，用户端显示通用错误。
- **会话管理** — 完全委托 WHMCS 会话管理器，不手动调用 `session_start()`。
- **登录方式** — 使用官方 `CreateSsoToken` API 生成限时一次性令牌，不直接操作 session。

## OAuth 服务器响应格式

**令牌端点**（`POST /oauth/token`）— 标准 OAuth2 令牌响应：
```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

**用户信息端点**（`GET /oauth/userinfo`）— 至少返回：
```json
{
  "sub": "unique-user-id",
  "email": "user@example.com",
  "name": "Display Name",
  "picture": "https://example.com/avatar.jpg"
}
```

`sub` 字段（或 `id` 作为回退）用作关联账户的唯一标识。

## License

MIT
