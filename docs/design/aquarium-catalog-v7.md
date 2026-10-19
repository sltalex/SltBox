# 水族馆目录 v7

本次只扩充内容和浏览分组，不修改账号余额、已购物品实例或已有布景。

| 运行时类别 | 数量 | 素材风格 |
| --- | ---: | --- |
| fish | 150 | 原创写实风格 SVG 插画 |
| coral | 20 | 原创卡通 SVG |
| algae | 20 | 原创卡通 SVG |
| snail | 20 | 原创卡通 SVG |
| plant / decoration | 各 50 | 保留已有写实 SVG |
| shrimp / crab / shellfish | 各 10 | 保留已有卡通 SVG |
| pack | 1 | 保留水族卡包 |

合计 341 个商品，340 对 SVG 原稿与透明 PNG。PNG 由原稿导出，供现有 WinUI
与 Native AOT 素材管线使用；没有引入在线图片依赖或第三方照片。

## 鱼类分组

`habitat` 是商城、背包使用的可选浏览字段，不改变 `category=fish`、物品 ID
或游动逻辑。分类互斥：淡水鱼 32 种、热带淡水观赏鱼 53 种、海水鱼 65 种。
热带海水鱼归入海水鱼，不重复计数。此分组不是生物混养适宜性建议。
旧缓存缺少 habitat 时仍可在“全部鱼类”中显示。

珊瑚、海藻、螺类均可购买、投放、收回、缩放和分层摆放。海藻轻摆，珊瑚与螺类
以底部为锚点静态展示。卡包现有规则自动包含所有非卡包商品。

## 清单与验证

- `aquarium-fish-expansion-plan.json`：全部 150 种鱼，含物种身份、价格、分组、尺寸与速度。
- `aquarium-reef-expansion-plan.json`：珊瑚、海藻各 20 种。
- `aquarium-snail-expansion-plan.json`：螺类 20 种。

复现及检查素材：

```powershell
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/generate-aquarium-svg-assets.py
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/generate-aquarium-svg-assets.py --check
php server/deskbox-api/tests/aquarium_service_test.php
dotnet test .\tests\DeskBox.Tests\DeskBox.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64 -p:RuntimeIdentifier=win-x64
```

## 部署边界

此内容更新不需要新增数据库迁移。服务端目录权威仍位于
`server/deskbox-api/api/_app/aquarium.php`；生产启用需要配套发布 API 和包含新素材的客户端。
仅本地构建不会使线上商城出现新品。v7 接受目录版本 2–6 的未确认购买重试，价格仍由服务端决定。
本次开发不执行生产上传、购买或数据库操作。

## 本地验证结果（2026-10-18）

- 340 对 SVG / PNG：自包含、透明度、边界与重复检查通过；已查看各组预览。
- PHP 六组服务测试全部通过；水族馆购买/幂等/投放/收回新增用例通过。
- x64 水族馆与本地化定向测试：183 / 183 通过。
- x64 全量测试：5069 通过、2 失败，共 5071。失败为已有独立商城页面改动引起的
  `SettingsDeferredSectionsTests.SearchCatalogCoversUncreatedPagesAndCannotDriftFromTheirXaml`
  （搜索目录缺 AquariumStore）及
  `AotStage4D1BContractTests.LocalizedHeaderAndDescription_UseTheFrozenTypedControlSet`
  （冻结计数 209，实际 210）；本次未修改这些契约或其对应搜索目录。
- canonical Debug 构建成功；全部 680 个 SVG/PNG 与输出目录 SHA-256 一致。
- 未执行生产 MySQL 测试、线上购买验证或 Native AOT 发布。
