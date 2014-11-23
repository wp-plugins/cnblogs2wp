=== cnblogs2wp ===
Contributors: cgfeel
Donate link: 
Tags: importer, cnblogs, oschina, wordpress
Requires at least: 3.0
Tested up to: 4.01
Stable tag: 0.2.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

将博客园（http://www.cnblogs.com/）以及开源中国-博客（http://www.oschina.net/blog）数据转换至wordpress中

== Description ==

**支持连接：**
http://levi.cg.am/archives/3759

有什么问题以及意见请在这里提出来，我会做出及时修正

将博客园（http://www.cnblogs.com/）以及开源中国-博客（http://www.oschina.net/blog）数据转换至wordpress中

在11年的时候就发布过一个数据导入的插件，最近有朋友反馈会报错。经过检查问题应该出在xml文件检测上。

在重新优化这款插件之前，就一直有个想法，希望能够按照官方提供的wordpress-importer的文件导入流程来优化这款插件流程。由于时间关系，一直搁置没有动过。介于这次机会重写了一遍这款插件。

== Screenshots ==

1. 导入博客园（cnblogs）文章到wordpress中
2. 导入开源中国（osc）博客文章到wordpress中

== Installation ==

在线安装方法：

1. 点击“安装插件”搜索cnblogs即可找到插件
2. 点击安装插件，等待wordpress在线安装完毕
3. 在插件管理中启动插件

离线安装方法：

1.下载离线插件包并解压
2.复制目录到/wp-content/plugins/目录下
3.进入wordpress控制台
4.插件管理中找到并启用“转换博客园、开源中国博客文章到wordpress”

数据导入方法：

1.点击“工具-导入”，在列表中找到并选择“博客园或开源中国的数据导入”
2.上传对应的数据，导入按照流程导入

== Changelog ==

= 0.2.3 =
* 修正一处正则匹配

= 0.2.2 =
* 向下兼容至php5.2，详细见：Upgrade

= 0.2.1 =
* 新增开源中国(osc)博客文章导入wordpress
* 优化文章导入方式，避免重复导入
* 导入文章支持选择作者、分类归属
* 导入文章允许下载远程附件
* 修正博客园cnblogs文章导入，增加导入数据文件类型检测
* 按照wordpress-import官方插件流程重写了文件的导入方法

= 0.1.1 =
* 支持cnblogs随笔导入wordpress

== Upgrade Notice ==

= 0.2.3 =
* 修正一处正则匹配

= 0.2.2 =
* 向下兼容：调整了函数中的闭包方法
* 向下兼容：去掉了直接获取函数返回的数组变量

== Frequently Asked Questions ==

1.cnblogs的数据文件是xml，osc的数据文件是htm，不能混淆导入
2.导入文件大小根据wordpress设定来决定的，若你导入的数据文件超出了服务器、主机限制，请自行百度或google搜索：“wordpress 文件上传限制”
3.需要浏览器支持js运行，否则筛选分类无效

== Filters ==

The importer has a couple of filters to allow you to completely enable/block certain features:

* `import_allow_create_users`: return false if you only want to allow mapping to existing users
* `import_allow_fetch_attachments`: return false if you do not wish to allow importing and downloading of attachments
* `import_attachment_size_limit`: return an integer value for the maximum file size in bytes to save (default is 0, which is unlimited)

There are also a few actions available to hook into:

* `import_start`: occurs after the export file has been uploaded and author import settings have been chosen
* `import_end`: called after the last output from the importer
