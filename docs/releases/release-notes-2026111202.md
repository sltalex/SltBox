# SltBox 2026111202

本次为 `2026111201` 的修复版，解决共享小镇灰度测试账号在安装包中看不到“小镇预览”入口的问题。

## 修复内容

- 共享小镇入口和窗口不再被 Debug 编译条件排除，Release/Native AOT 安装包包含完整入口。
- 入口仍由服务端 `town/world.php` 的 `enabled` 与 `graylisted` 返回值控制，普通账号不会看到入口。
- 增加 Release 编译契约测试，确保小镇入口、灰度检查和窗口代码持续进入正式构建。

## 验证

- 相关设置测试：14/14 通过。
- Release x64 编译：0 错误。
- Debug 构建已重新启动并使用当前仓库输出。

**完整变更对比：** https://github.com/sltalex/SltBox/compare/v2026111201...v2026111202
