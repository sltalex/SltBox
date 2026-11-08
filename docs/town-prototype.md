# 小镇可玩原型

此功能仅编入 Debug，入口为“设置 → 种树 → 小镇预览”。重复打开会激活已有窗口。生产种树、到访、果实、动物、真实背包和能量服务均不接入此演示。

## 体验方式

- 漫游：方向键或 WASD 行走，镜头跟随人物；滚轮使用 1～4 倍整数缩放。
- 编辑：左栏选素材，在网格中放置；拖动已有物件移动，选中后移除，或使用撤销。编辑模式可拖动地图空白区域查看周边。
- 每种装饰提供 3 件演示库存，共 16 种，最多 48 件。地形和道路固定；水面、主树及出生区不可覆盖。
- 树木阶段下拉框仅改变演示树外观。恢复示例布局可以撤销，只有明确点击“保存小镇”才会上传。
- 未登录可体验；已登录时按账号读取云端布局。保存失败保留本机草稿，重试复用未确认的操作编号；冲突需要明确重新载入。
- 关闭有修改的窗口会询问保存、保留草稿或继续编辑。未登录的演示不保存本机草稿。

## 代码边界

- `Services/TownPrototypeSession.cs`：通过现有认证通道读写地图，处理 20 秒超时、账号切换、按账号的草稿缓存和操作重试。
- `Core/Persistence/TownPrototypeDraftFileStore.cs`：在持久化域中原子替换独立草稿文件。
- `Views/TreeTownPrototypeWindow.cs`：独立 WebView2 窗口，虚拟本地主机加载资源，禁止导航外部网站；关闭后释放事件和 WebView。
- `Assets/Tree/TownPrototype/`：本地 Phaser 3.90.0、页面、地图规则、目录和 PNG。仅复制到 Debug 输出，不发布到 Release。
- `server/deskbox-api/api/_app/tree_town_prototype.php`：独立布局校验和事务保存；账号只由现有登录会话确定。
- `database/021_tree_town_prototype.sql`：只创建 `tree_town_prototype_profiles`，不修改现有树木或库存表。

GET `/api/v1/tree/town-prototype/state.php` 在没有记录时返回示例且不写库。POST `actions.php` 接收 `save_scene`、基础场景版本、操作编号及布局；提交时锁定当前用户行，检测并发冲突，同一请求重试不重复保存。

## 素材溯源和验证

源目录：`D:\work\C#\星露谷物语素材\Stardew valley`。运行 `python scripts/prepare-town-prototype-assets.py` 可重建素材；添加 `--verify-only` 只校验。`crop-manifest.json` 记录源文件哈希、明确切割坐标和合成落点。脚本生成透明棋盘格成品预览、原图框选以及人物动画逐帧对照，不改原始素材。

验证入口：

```powershell
node scripts/verify-town-prototype.mjs
php server/deskbox-api/tests/town_prototype_service_test.php
php server/deskbox-api/tests/town_prototype_endpoint_test.php
dotnet test tests/DeskBox.Tests/DeskBox.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64 --filter FullyQualifiedName~TownPrototypeSessionTests
dotnet build src/DeskBox/DeskBox.csproj --no-restore
```

启动前停止本仓库内的旧实例，常规运行路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。Debug 下可为当前启动进程设置 `DESKBOX_TOWN_PREVIEW=1`，启动完成后自动打开预览；正常启动不设置该环境变量。

可选原生验收入口在 `TreeTownPrototypeWindow.Probe.cs`，只有明确设置 `DESKBOX_TOWN_PROBE_DIRECTORY` 才执行。它使用本窗口的本地页面，验证当前登录账号的保存、读回、恢复原演示阶段及窗口缩放，输出不含账号与凭证的 JSON 和窗口内容 PNG。正常使用不设置此环境变量。

## 移除原型

关闭预览后，移除独立 `TownPrototypeSession`、`TreeTownPrototypeWindow`、`Assets/Tree/TownPrototype`，及设置按钮、App 中 Debug 自动打开入口、项目中的 Debug 资源条目即可移除客户端原型。云端原型表和接口独立，不需要迁移正式树木数据。删除云端布局属于另行操作，不能在移除客户端时自动执行。
