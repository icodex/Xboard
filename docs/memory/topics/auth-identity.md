# 主题：邮箱身份与运行时扩展依赖

## 邮箱规范化（上游 d826a3b，2026-11-18 合并）

- 唯一事实源：`App\Models\User::normalizeEmail()` —— trim → NFC → local part 小写 → 域名 `idn_to_ascii`（UTS46，std3+bidi+contextj，non-transitional）→ 小写。
- 存储入口：`User` 的 `email` Attribute mutator 全部走它；查询入口：`scopeByEmail`（会再规范化一次）。所有按邮箱查用户的地方都必须用 `byEmail`，裸 `where('email', ...)` 在二进制排序规则下不再大小写不敏感。
- 请求层：`App\Http\Requests\Concerns\NormalizesEmail` trait 在 `prepareForValidation()` 规范化，校验规则随之加 `max:64`（列宽）。
- `v2_user.email` 排序规则改二进制（MySQL `utf8mb4_bin` / PG `COLLATE "C"` / SQLServer `Latin1_General_100_BIN2`），迁移 `2026_11_02_000001_normalize_user_email_identity`；上游先跑 `assertNoEmailCollisions()`（含长度校验），撞车直接抛异常并给出用户 ID，不做半途写入。
- 缓存键（登录密码错误计数、忘记密码限流、邮箱验证码）均以规范化邮箱为键。

## 运行时扩展（部署硬依赖）

- 面板自 d826a3b 起硬依赖 **intl**（`Normalizer`、`idn_to_ascii`）与 **mbstring**。基础镜像 `phpswoole/swoole:php8.2-alpine` 只自带 mbstring，不含 intl。
- 官方镜像 `ghcr.io/cedar2025/xboard:latest` 实测 `class_exists("Normalizer")` 为 false —— 上游 Dockerfile 同样漏装，属于上游待修项；本仓 `Dockerfile` 已补 `install-php-extensions intl` 并加入启动期扩展断言，CI 扩展清单已同步。
- 结论：任何自定义镜像/宿主机 PHP 环境必须提供 intl，否则登录注册即 fatal。
