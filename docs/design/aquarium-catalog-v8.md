# 水族馆目录 v8：水母

新增 10 款原创自然史风格 SVG：月亮水母、狮鬃水母、蓝脂水母、炮弹水母、倒立水母、花笠水母、海刺水母、水晶水母、栉水母、僧帽水母。
每款使用独立的伞体或气囊、口腕、触手与配色。清单和科学名称见
`aquarium-jellyfish-expansion-plan.json`。`jellyfish` 是目录分类，其中
栉水母属于栉水母动物，僧帽水母是管水母群体；水母与其他水族馆物品不在商城
直接售卖，只能作为卡包抽取奖励。

目录共 351 个商品；物种素材共 350 对 SVG 原稿和透明 PNG。SVG 原稿保留在
`src/DeskBox/Assets/Aquarium/Svg/`，PNG 是适配现有 WinUI 素材管线的导出。
新水母位于目录和背包“水母类”，支持抽卡重复获得、数量汇总、放入和收回，
并沿用游动生物的自动水层展示。与鱼类相同，水母不显示手工布景编辑按钮。
商城仅显示水族卡包，卡包抽取规则包含这些新商品。

## 素材复现

水母 SVG 是手工矢量原稿；生成器保留原稿，校验后导出 PNG。
两种命令使用同一份导出逻辑，固定透明画布为 240 × 220。

```powershell
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/generate-aquarium-svg-assets.py --category jellyfish
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/generate-aquarium-svg-assets.py --category jellyfish --check
uv run --with resvg-py==0.5.0 --with pillow==13.0.0 python scripts/aquarium_svg/jellyfish.py --render-png
php server/deskbox-api/tests/aquarium_service_test.php
dotnet test .\tests\DeskBox.Tests\DeskBox.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64
```

水母 `--check` 重新渲染 SVG，检查透明边界，并逐像素比较导出 PNG，防止
原稿截边或旧导出被误判为通过。总览生成在 `.artifacts/aquarium-svg-preview/jellyfish.jpg`。

## 发布

服务器目录权威仍是 `server/deskbox-api/api/_app/aquarium.php`。v8 兼容版本
2–7 的排队购买，价格由服务端确定；不需要数据库迁移。上线需要同时发布目录 API
与包含新资源的客户端，仅本地 Debug 构建不会更新线上商城。
