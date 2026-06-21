# 考研背诵系统 (PHP + MySQL版)

## 环境要求
- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx (支持URL重写)

## 安装步骤

### 1. 创建数据库
```sql
-- 在MySQL中执行 database.sql 文件
source database.sql;
```

### 2. 配置数据库连接
编辑 `api.php` 文件，修改数据库连接信息：
```php
$host = 'localhost';
$dbname = 'recite_system';
$username = 'root';      // 修改为你的数据库用户名
$password = '';          // 修改为你的数据库密码
```

### 3. 部署到虚拟主机
1. 将所有文件上传到虚拟主机的网站根目录
2. 确保虚拟主机支持 `.htaccess` 文件
3. 确保PHP已启用PDO MySQL扩展

### 4. 访问系统
- 打开浏览器访问你的域名
- 默认账号：admin / 123456

## 文件说明
- `index.html` - 前端页面
- `api.php` - PHP API接口
- `database.sql` - 数据库初始化脚本
- `.htaccess` - Apache URL重写规则

## 功能特性
- ✅ 用户注册/登录
- ✅ 闪卡/段落内容管理
- ✅ 分类管理
- ✅ 间隔重复复习
- ✅ 学习日历
- ✅ Excel单词导入
- ✅ 头像上传
- ✅ 数据同步

## 本地开发
```bash
# 启动PHP内置服务器
./启动背诵系统.sh

# 或直接运行
php -S 0.0.0.0:8000
```

## 虚拟主机部署
1. 登录虚拟主机面板
2. 创建MySQL数据库
3. 导入 `database.sql`
4. 上传所有文件到网站根目录
5. 修改 `api.php` 中的数据库配置
6. 访问网站测试
