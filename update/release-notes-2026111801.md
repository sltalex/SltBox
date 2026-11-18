# SltBox 2026111801

发布日期：2026-11-18。

## 随记与待办字体颜色

- 编辑器工具栏增加内联字体颜色选择，可对选中文字设置颜色，也可在没有选中文字时设置后续输入颜色。
- 颜色以安全的 `data-deskbox-color` 标记保存，Markdown 展示端解析后保留局部颜色。
- 随记/待办列表预览直接显示正文中的字体颜色，不需要打开内容才能确认效果。
- 恢复鼠标悬停时的预览自动展开，并使用编译绑定保证 Native AOT 兼容。

## 稳定性与发布

- 移除会导致 WinUI 崩溃的嵌套颜色 Flyout/WrapGrid/Button 结构，改为编辑器内联颜色条。
- x64 Direct Full Native AOT 安装包、MSIX 和 SHA-256 校验文件已生成。
- 运行既有 Markdown/QuickCapture 专项测试，并通过 Native AOT 阶段审计。
