=== cnblogs2wp ===
Contributors: cgfeel
Donate link: 
Tags: importer, cnblogs, oschina, csdn, lofter, 点点, wordpress
Requires at least: 3.1.0
Tested up to: 4.2
Stable tag: 0.6.5
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

支持导入文章到wordpress的博客，平台包含有：博客园、OSChina、CSDN、点点、Lofter

== Description ==

**如果您喜欢这款插件，请点击下面链接，给与5星好评哦~亲！！：**
https://wordpress.org/support/view/plugin-reviews/cnblogs2wp

**支持连接：**
http://levi.cg.am/archives/3759

有什么问题以及意见请在这里提出来，我会做出及时修正

> 注：本人一直习惯使用PHP新特性、新语法，这样可能会造成插件在一些老的PHP环境中执行错误；比如PHP 5.3，如果您执行出现错误，请您将错误提示反馈给我，我会做出调整。
>
> 如果你的PHP版本比PHP5.3还要低，那可能就不在我支持范围之内了，因为这样您可能连wordpress都有点难跑动，建议升级PHP环境。

**支持导入文章的平台：**

* [博客园](http://www.cnblogs.com/)
* [开源中国](http://www.oschina.net/blog)
* [CSDN](http://blog.csdn.net/)
* [点点](http://www.diandian.com/)
* [Lofter](http://www.lofter.com/)

将博客园（http://www.cnblogs.com/）以及开源中国-博客（http://www.oschina.net/blog）数据转换至wordpress中

在11年的时候就发布过一个数据导入的插件，最近有朋友反馈会报错。经过检查问题应该出在xml文件检测上。

在重新优化这款插件之前，就一直有个想法，希望能够按照官方提供的wordpress-importer的文件导入流程来优化这款插件流程。由于时间关系，一直搁置没有动过。介于这次机会重写了一遍这款插件。

== Screenshots ==

1. 开始界面
2. 设置导入界面
3. 导入CSDN博客文章前需要对博客做验证
4. 数据导入过程
5. 数据导入日志记录

== Installation ==

在线安装方法：

1. 点击“安装插件”搜索cnblogs即可找到插件
2. 点击安装插件，等待wordpress在线安装完毕
3. 在插件管理中启动插件

离线安装方法：

1. 下载离线插件包并解压
2. 复制目录到/wp-content/plugins/目录下
3. 进入wordpress控制台
4. 插件管理中找到并启用“转换博客园、开源中国博客文章到wordpress”

数据导入方法：

1. 点击“工具-导入”，在列表中找到并选择“博客搬家”
2. 上传对应的数据，导入按照流程导入

== Changelog ==

= 0.6.5 =
* 修复“博客园”导入数据默认转换大小写
* 修复所有平台导入数据默认过滤符号`\`
* 更新`user-agent`

= 0.6.4 =
* 修复附件导入遗漏的BUG，详细见：[http://levi.cg.am/wiki/cnblogs数据导入wordpress/数据导入插件更新说明/博客搬家到wordpress-版本0-6-4更新说明](http://levi.cg.am/wiki/cnblogs%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5wordpress/%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5%E6%8F%92%E4%BB%B6%E6%9B%B4%E6%96%B0%E8%AF%B4%E6%98%8E/%E5%8D%9A%E5%AE%A2%E6%90%AC%E5%AE%B6%E5%88%B0wordpress-%E7%89%88%E6%9C%AC0-6-4%E6%9B%B4%E6%96%B0%E8%AF%B4%E6%98%8E)；
* 更新了User-Agent；
* 去掉JS中一处打印；

= 0.6.3 =
* 修复目录没有操作权限无法写入数据的Bug；

= 0.6.2 =
* 修复一处删除临时数据的小Bug；

= 0.6.1 =
* 支持通过安装[wordpress第三方补丁包][1]，上传导入较大的数据文件；
* 重写了XML解析规则，更好的兼容不同平台的数据文件；
* 重写了导入规则，适应较大的数据导入到wordpress，对数据较大的单一博客以及多博客站点做了优化；
* 修正获取数据后时间转换（由于wordpress以UTC的方式统计时间，中国地区遗漏了8小时，已修复）
* 解决“点点”博客数据导入遗漏的问题（由于“点点”以时间来制定日志地址，由于上面时间BUG造成问题，已修复）；
* 优化了“点点”轻博客导入图片类型数据（“点点”默认只提供压缩后的图片，此次优化将尽可能从博客中获取原图）；
* 解决“点点”博客文章内容中的图片漏抓的问题
* 新增数据导入日志记录
* 修正终止数据导入失败的bug

详细见：[http://levi.cg.am/wiki/cnblogs数据导入wordpress/数据导入插件更新说明/wordpress-博客搬家-版本0-6-1更新详情][2]

  [1]: https://wordpress.org/plugins/wp-patch-levi/
  [2]: http://levi.cg.am/wiki/cnblogs%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5wordpress/%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5%E6%8F%92%E4%BB%B6%E6%9B%B4%E6%96%B0%E8%AF%B4%E6%98%8E/wordpress-%E5%8D%9A%E5%AE%A2%E6%90%AC%E5%AE%B6-%E7%89%88%E6%9C%AC0-6-1%E6%9B%B4%E6%96%B0%E8%AF%A6%E6%83%85


= 0.5.1 =
* 增加Lofter轻博客文章导入wordpress；
* 更新UA信息；
* 调整导入菜单排序；

= 0.4.3 =
* 修正一处错误，向下兼容低版本php；

= 0.4.2 =
* 修正一处错误，向下兼容低版本php；

= 0.4.1 =
* 增加导入”点点”博客文章；
* 插件中心增加“开始导入”引导链接；
* 支持导入文章形式（需导入的数据提供支持）；
* 允许导入隐私文章（需导入的数据提供支持）；
* 允许置顶文章（需导入的数据提供支持）；
* 兼容更新至wordpress 4.1；
* 提高分类导入效率：去除导入分类步骤；
* 修复分类导入：新增分类为已存在的分类名称时导入不正确；
* 修正强制终止数据导入后，临时状态数据未被清除情况；

= 0.3.1 =
* 新增插件机制，支持以“插拔”的方式导入数据
* 重新修改插件导入数据的方式，采用“无阻塞”的方式导入数据
* 新增CSDN博客文章导入到wordpress

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

= 0.6.5 =
* 修复wordpress数据导入中过滤掉特殊字符问题

= 0.6.4 =
* 修复附件导入遗漏的BUG

= 0.6.3 =
* 修复目录没有操作权限无法写入数据的Bug；

= 0.6.2 =
* 修复一处删除临时数据的小bug；

= 0.6.1 =
* 对数据导入的方式进行调优，更适合大数据导入到wordpress；

= 0.5.1 =
* 增加Lofter轻博客文章导入wordpress；

= 0.4.3 =
* 修正一处错误，向下兼容低版本php；

= 0.4.2 =
* 修正一处错误，向下兼容低版本php；

= 0.4.1 =
* 增加点点文章导入到wordpress，bug修复

= 0.3.1 =
* 增加csdn博客文章导入到wordpress

= 0.2.3 =
* 修正一处正则匹配

= 0.2.2 =
* 向下兼容：调整了函数中的闭包方法
* 向下兼容：去掉了直接获取函数返回的数组变量

== Frequently Asked Questions ==

1. cnblogs的数据文件是xml，osc的数据文件是htm，不能混淆导入
2. 导入文件大小根据wordpress设定来决定的，若你导入的数据文件超出了服务器、主机限制，请自行百度或google搜索：“wordpress 文件上传限制”
3. 需要浏览器支持js运行，否则筛选分类无效

== Filters ==

更多请参考：[数据导入插件中的钩子](http://levi.cg.am/wiki/cnblogs%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5wordpress/%E6%95%B0%E6%8D%AE%E5%AF%BC%E5%85%A5%E6%8F%92%E4%BB%B6%E4%B8%AD%E7%9A%84%E9%92%A9%E5%AD%90)

The importer has a couple of filters to allow you to completely enable/block certain features:

* `import_allow_create_users`: return false if you only want to allow mapping to existing users
* `import_allow_fetch_attachments`: return false if you do not wish to allow importing and downloading of attachments
* `import_attachment_size_limit`: return an integer value for the maximum file size in bytes to save (default is 0, which is unlimited)

There are also a few actions available to hook into:

* `import_start`: occurs after the export file has been uploaded and author import settings have been chosen
* `import_end`: called after the last output from the importer
