# 种树 Three.js 实验前端

默认使用原来的 2D。“设置 → 功能格子 → 种树 → 3D 实验效果”可立即切换。
开关保存在本机应用根目录的 `tree-visual/presentation.json`，不属于账号进度或云同步协议。
WebView2 缓存也位于 `tree-visual/webview2`，两者均在账号备份扫描的 `data` 目录之外。
关闭开关即可恢复 2D；失败回退不会改写用户的本机偏好，关闭再开启可重试。

## 2.5D 地图与观察（2026-11-05）

3D 实验模式现在采用正交相机和低多边形地图。主树位于中央空地，周围有林木、小径、池塘、石块和长椅。
地图外仍保留 alpha，格子背景可以透出；不改变窗口尺寸和原生能量/阶段控件。

- 按住鼠标左键拖动：绕观察点旋转，垂直拖动调整俯角。
- 地图上滚轮向上放大、向下缩小，缩放限制为默认的 0.55～2.8 倍；Home 同时恢复默认缩放。
- 单击地图取得焦点，再按方向键：沿当前相机的左右、前后方向观察环境，移动范围限制在地图内。
- Home：回到主树的初始视角。
- 单击果实、动物仍回传原来的 ID；拖动超过阈值后不触发点击，捕捉丢失或隐藏会取消拖动。
- 关闭动画后仍可操作相机，每次操作单独请求重绘；隐藏时不接受相机输入。

`Assets/Tree/ThreeD/tree-map.js` 只生成环境和相机控制，观察位置不保存到账号、进度或云端。
WinUI 原生宿主捕获指针并转发归一化旋转量及方向键；浏览器独立预览也支持相同操作。
桌面固定窗口将 Tree3DView 识别为键盘输入目标，复用现有窗口激活与恢复桌面层级流程。
本次修改前额外备份：`C:\Users\Administrator\.codex\backups\deskbox-tree-map-20261105-160224`。

可重复浏览器验收：启动 `Assets/Tree` 的本地静态服务（127.0.0.1:8765），打开 `/ThreeD/index.html`，
运行 `playwright-cli run-code --filename scripts/verify-tree-map.js`。
该脚本验证真实鼠标拖动、四个方向键、Home 复位、三种相机角度的果实/动物命中、
五个阶段、七种天气、透明像素、帧传输和隐藏恢复。截图保存在 `output/playwright/tree-map-*.png`。
相关 x64 测试（3D 展示、JSON 契约、桌面窗口激活）30 项通过。

### 地图高度自适应

种树内容使用两行 Grid：地图行在 2.5D 模式占据剩余高度，底部能量、提示和阶段进度按内容占高。
地图不设固定高度或额外最小高度；底部信息显隐会自动重新分配地图空间。
2D 和初始化失败回退使用 Auto 行与原有 130 高度，避免改变原有 2D 外观。
窗口尺寸变化只通过 ResizeObserver 更新画布和相机宽高比，不重置观察位置、旋转和缩放。
本次备份：`C:\Users\Administrator\.codex\backups\deskbox-tree-height-20261105-162657`。
浏览器验收增加宽/窄/高/矮视口连续切换，确认画布填满且返回原尺寸时相机画面一致。
实际原生控件布局探针通过 32 组组合（4 种宽高 × 能量/提示/进度 8 种显隐组合），
逐组验证地图高度为可用高度减去底部信息和间隔、底部信息未越界，以及 2D 恢复到 130 高度。
探针只临时调整控件布局并恢复，不保存格子尺寸或账号数据；诊断代码已移除。
相关 x64 测试 30 项通过，浏览器地图完整验收通过。

### 旋转后的果实与装饰挂载

原先果实和树上装饰沿用平面坐标并放在固定前景深度，且关闭深度检查，
因此从侧面、背面或较高俯角观察时会脱离树体，还会穿透树叶显示。
现在将原有坐标投射到实际树冠/树干几何表面；超出新树冠轮廓的点吸附到最近表面，
保留少量表面间距避免闪烁。挂载只在物件或阶段更新时计算，不随相机重新定位。
树冠装饰与果实共用树冠摆动，树干装饰固定在树干，地面装饰使用底部落地的竖直平面，
只随观察方向水平转向，不随相机俯仰而离地。
果实与装饰启用深度检查；果实命中检测同时检查树体及地图遮挡，避免点击树背面的果实。
这些转换只作用于实验版显示，不改变原生位置、库存、布局记录、2D 绘制或服务器协议。

修改前备份：`C:\Users\Administrator\.codex\backups\deskbox-tree-anchors-20261105-164254`。
`scripts/verify-tree-anchors.js` 在浏览器加载真实绘制代码，验证挂载表面距离、
36 组旋转/俯仰及移动缩放组合、可见与遮挡果实点击、地面接触、树干/树冠挂载和五阶段重建。
原始代码复现果实/装饰距树冠约 27/44 单位且关闭深度检查；修复后表面间距均为 3 单位。
正面、侧面、背面及俯视截图保存为 `output/playwright/tree-anchors-*.png`。

## 隔离和状态来源

- `Controls/WidgetContents/Tree3DView.cs`：本地 WebView2 宿主、初始化超时、消息校验、故障回退通知。
- `Controls/WidgetContents/TreeWidgetContent.ThreeD.cs`：读取现有服务并转换视觉状态，点击转到原有采摘和捕捉流程。
- `Controls/WidgetContents/Tree3DSceneState.cs`：只包含展示状态，使用 source-generated JSON。
- `Assets/Tree/ThreeD/`：Three.js 0.180.0、MIT 许可、本地页面和绘制脚本。运行时不请求 CDN。
- `Services/TreeVisualSettingsService.cs`、`Core/Persistence/TreeVisualSettingsFileStore.cs`：独立本机设置及备份恢复。

WebView2 将 `Assets/Tree` 映射到 `https://deskbox-tree3d.local`。导航限制与 CSP 只允许本地页面和资源。
原生 260×260 场景坐标、果实位置、动物运动轨迹与点击 ID 均由 C# 提供。
JavaScript 回传 `fruitTapped`、`wildlifeTapped` 和只用于显示的透明画面 `frame`，不会提交服务器请求或计算奖励。
能量文字、阶段进度和捕获状态继续使用现有原生控件。
页面隐藏时取消绘制循环，关闭动画时只在状态变化后绘制一次。SVG 在内存中栅格化后作为纹理，兼容 ANGLE。

常规非平台 Debug 输出显式选择 x64 `PlatformTarget`，避免 NuGet 默认选入 x86 的 WebView2 原生组件；输出路径仍为原有 Debug 路径。

### 透明背景

Three.js 的 alpha 清屏、HTML 透明背景和 WebView2.DefaultBackgroundColor 只控制网页层。
WinUI 的原生 WebView 合成桥另外读取主题背景色，浅色主题下会产生不透明的浅灰白色矩形。
先前仅将自身 WebView2.Resources 中的 `SolidBackgroundFillColorBase` 和
`SystemColorWindowColor` 覆盖为透明，探针虽确认合成桥 alpha 从 255 变成 0，
但用户仍反馈自定义背景被遮挡；颜色参数验证不能作为实际背景可见的验收。

当前 WebView2 保持布局尺寸并隐藏原生表面（Opacity=0、禁止命中和 Tab 焦点），
Three.js 每次绘制后生成带 alpha 的 PNG，由原生 WinUI Image 显示。
树、果实、动物和装饰由同一个透明帧呈现，格子的自定义背景仍由 WidgetShell 原样绘制。
动画帧最多约 15fps，始终只允许一帧在途，原生解码后确认再继续传输，避免解码队列积压。
静态状态、纹理异步加载和尺寸变化也会触发重绘；隐藏时停止传输，恢复后重新绘制。
原生指针坐标归一化后发给 JavaScript 命中检测，点击 ID 仍回到原有 C# 采摘/捕捉流程。
初始化以首张原生图像成功解码为就绪条件，隐藏期间不要求首帧，恢复显示后继续检查首帧超时。
不要改动应用全局主题资源，也不要使用桌面截图伪装透明背景。

## 基线和撤回

基线提交：`ddb3f33d`。
修改前备份：`C:\Users\Administrator\.codex\backups\deskbox-tree3d-baseline-20261105`。
目录包含完整 Git archive、SHA-256、提交、初始状态、相关文件清单及原有未跟踪目录文件副本。

需要完全移除时，先关闭开关，然后只撤回本次 `TreeWidgetContent.cs`、`TreeWidgetContent.Wildlife.cs`、
`TreeSettingsSection.cs`、项目依赖/锁文件与本地化的接入修改，再删除上面列出的独立 3D 文件。
同时撤回本次 JSON inventory 的增量和专用测试。不要整体覆盖工作树，也不要改动进度、库存、场景或发布产物。
现有 2D 绘制实现未删除。单独删除 3D 资源目录会安全回退，但完全清理代码必须一并撤回接入点。

## 验证记录（2026-11-05）

- 全量 x64 测试：5307 通过，0 失败；覆盖本机默认模式/保存/恢复、状态 JSON 和原有回调接入契约。
- 最终本机数据隔离调整后，相关 x64 测试 19 通过，0 失败，新增验证视觉设置不会进入账号备份。
- 常规 Debug 构建通过；修正后确认 WebView2 native DLL 为 x64。
- Edge + 本地 HTTP 页面：五阶段截图、PNG 果实/动物、SVG 装饰、七种天气、果实 ID/动物 ID 点击回传、静态模式、隐藏暂停和恢复通过。没有外部资源请求。
- 浏览器模拟 WebGL 不可用：收到 error，未收到 ready。
- 实际 canonical Debug 原生 WebView2：本地虚拟主机页面加载成功，首帧 ready 日志已确认；实际视口 300×130，并已保存嵌入页面截图。
- 实际原生宿主注入 WebGL context lost：日志确认自动回退 2D。
- 透明帧修复后，相关 x64 测试 19 通过；浏览器复验五阶段、七种天气、果实/动物点击、动画和隐藏恢复通过。
- 透明传输像素验证：300×130 帧与底色 #00c4a7 合成后，空白角落像素仍为 RGBA(0,196,167,255)；帧确认限流、归一化果实点击和隐藏停止通过。
- 实际用户种树 WidgetShell 的原生 RenderTargetBitmap 合成图为 136×199，确认现有背景照片显示在树和果实后方，无白色矩形；保存在 `output/playwright/tree3d-native-background.png`。临时捕获代码已移除。
- Windows 原生 UI 自动化工具初始化失败，尚未完成外层设置开关的鼠标操作、2D/3D 外窗尺寸对照与真实账号采摘/捕获奖励的端到端验收。真实账号奖励未用于破坏性测试。

浏览器验证截图和临时脚本保存在 `output/playwright/`；验证不代表正式发布验收。首次发布前仍需在真实桌面上检查设置切换、窗口隐藏恢复和交互。
