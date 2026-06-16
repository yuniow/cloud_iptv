# CloudIPTV

IPTV 直播源管理与分发系统

## 功能特性

- **咪咕源管理** — 自动抓取咪咕视频频道列表，支持游客模式和 VIP 模式
- **外部源管理** — 支持直连/抓取/订阅三种模式添加自定义播放源
- **内置源** — 纬来体育、Red Bull、4K卫视等精选频道
- **频道管理** — 分组管理、批量操作、频道隐藏/恢复、多线路切换
- **EPG 节目单** — 自动获取咪咕/CNTV EPG + 支持自定义 XMLTV 订阅源
- **播放管理** — 设备监控（7天活跃设备）、设备屏蔽（四重指纹）、频道热度排行
- **代理支持** — 全局代理 + 单源独立代理 + Cloudflare Workers 代理搭建指南
- **系统配置** — 画质设置、密码保护、频道检测、定时任务、安全管理
- **导出播放列表** — M3U / TXT / EPG(xml) 格式导出
- **暗色模式** — 支持亮色/暗色主题切换
- **响应式布局** — 适配桌面端和移动端

## 环境要求

- PHP >= 8.1
- PHP 扩展：pdo_sqlite, curl, xml, mbstring, zip
- SQLite 3

## 安装部署

### 1. 上传文件

将项目文件上传到 Web 服务器目录，例如 `/www/wwwroot/your-domain.com`

### 2. 配置 Web 服务器

**Nginx 示例：**
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /www/wwwroot/your-domain.com/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm/php8.1.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

**Apache / 宝塔面板**：项目 `public/` 目录下已有 `.htaccess`，自动支持 URL 重写。

**PHP 内置服务器（开发测试）：**
```bash
php -S 0.0.0.0:10800 -t public router.php
```

### 3. 设置目录权限

```bash
chmod -R 755 data/
chmod 666 data/cloudiptv.db
```

### 4. 升级更新

⚠️ **更新代码时请勿覆盖 `data/` 目录**，该目录包含数据库和配置文件：

```bash
# 推荐更新方式：只更新代码文件，保留 data/ 目录
git pull
# 或手动替换以下目录/文件：app/、config/、public/、vendor/、cron_*.php
```

### 5. 访问后台

浏览器打开 `http://your-domain/admin`，默认账号密码：`admin / admin`

> ⚠️ 首次登录后请立即修改管理员密码（系统配置 → 安全管理）

## 目录结构

```
├── app/                    # PHP 后端代码
│   ├── Config/             # 配置（路由、AppConfig）
│   ├── Controllers/        # API 控制器
│   ├── Helpers/            # 工具类（加密、HTTP、编码）
│   └── Services/           # 业务服务（咪咕、EPG、播放列表、检测等）
├── config/                 # 内置源配置
├── data/                   # 数据库和缓存文件
├── public/                 # Web 入口目录
│   ├── api/                # API 入口
│   ├── react/              # 前端构建产物
│   └── vendor/             # DPlayer、HLS.js 播放器
├── vendor/                 # Composer 依赖
├── cron_epg.php            # EPG 更新定时任务
├── cron_probe.php          # 频道检测定时任务
└── cron_refresh.php        # 源刷新定时任务
```

## 定时任务

在宝塔面板或 crontab 中配置：

```bash
# 每8小时更新节目信息
0 */8 * * * php /path/to/project/public/cron.php epg

# 每30分钟检测频道
*/30 * * * * php /path/to/project/public/cron.php probe

# 每12小时刷新所有源
0 */12 * * * php /path/to/project/public/cron.php refresh
```

## 技术栈

- **后端**：PHP 8.5 + SQLite + 原生路由（无框架依赖）
- **前端**：React 18 + MUI 5 + Vite + Apple HIG 设计风格
- **播放器**：DPlayer + HLS.js

## 版本

### v1.1.0 (2026-06-16)
- 新增多线路频道切换 — 央视/卫视频道支持咪咕与外部源线路切换
- 新增外部源频道自动合并 — 同名频道跨源自动合并为多线路
- 修复外部源频道不显示 — 外部源更新后自动重建播放列表
- M3U 多线路格式兼容 — 同 tvg-id 不同 URL，Emby/Kodi EPG 自动绑定

### v1.0.1
- 修复已知问题

### v1.0.0
- 首次发布

## 许可证

GPL-3.0
