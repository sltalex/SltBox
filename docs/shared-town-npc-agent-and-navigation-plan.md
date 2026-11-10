# 共享小镇村民 Agent 与动态通行系统开发方案

## 1. 目标

在现有共享小镇中加入可配置的村民和 Agent 能力：

- 管理员可以在 Web 端添加、编辑、停用和删除村民。
- 每个村民拥有姓名、外观、人格、家庭背景、生活目标、日程和人际关系。
- 村民能够自主行走、工作、休息、交谈、使用道具和协同完成任务。
- 地图素材不仅描述外观，还描述占地、碰撞、地形高度、通行条件和可用道具。
- 地图素材经常更换时，服务器能够重新计算通行规则，并使村民重新规划路线。
- AI 可以决定“想做什么”和“是否需要道具”，服务器决定“能不能做”和“如何执行”。
- 模型、模型供应商、调用额度和 Agent 行为可以在 Web 端配置。

## 2. 推荐结论

当前 DeskBox 架构可以继续承载这套系统，但不建议把 Agent 循环放进 WebView 或 PHP 请求中。推荐保留现有客户端、PHP API 和 MySQL，增加一个独立的 NPC Agent 服务：

```text
Web 管理后台
      │
      ▼
PHP API ─────────────── MySQL
      │                    │
      │                    ▼
      └──────────────► NPC Agent Orchestrator (.NET Worker)
                              │
                  ┌───────────┼───────────┐
                  ▼           ▼           ▼
             模型网关      寻路服务     任务/事件系统
           LiteLLM       Grid A*      资源与关系
                  │
          云端模型 / Ollama / vLLM
                              │
                              ▼
                    SharedTownSession
                              │
                              ▼
                         WebView2 / Phaser
```

第一版不引入 Nakama 或重写实时服务器。当前小镇使用区块加载、变化游标和认证会话，足以支撑小规模村民。只有当村民数量、在线用户数量或实时同步要求明显提高时，才考虑引入 Nakama 或 Colyseus。

## 3. 当前架构评估

### 已具备的能力

| 能力 | 当前实现 | 可复用方式 |
| --- | --- | --- |
| 地图和区块 | 768×768 地图、24×24 区块 | NPC 使用同一张地图和区块边界 |
| 素材目录 | Web 后台发布图集和素材 | 增加导航属性字段 |
| 素材占地 | `footprint_json` | 作为 NPC 和玩家的空间占用 |
| 素材碰撞 | `collision_json` | 扩展为阻挡区域和交互区域 |
| 地图物件 | `town_objects` | NPC 读取并避开动态物件 |
| 玩家位置 | `town_player_positions` | 复用位置、区块和站立检查逻辑 |
| 地图变化 | `town_change_log` | 增加导航版本和 NPC 重规划事件 |
| 客户端桥接 | `SharedTownSession` | 增加 NPC 快照和变化消息 |
| Phaser 渲染 | 当前已有对象、人物和层级渲染 | 增加 NPC 精灵、名称和状态标记 |

### 尚缺少的能力

- NPC 定义、家庭、人格、日程和关系数据。
- NPC 运行状态、当前任务、路线和库存。
- 服务器权威寻路和通行能力判断。
- 楼梯、悬崖、河道、桥梁等跨区域连接规则。
- 道具能力和消耗规则。
- Agent 调度、记忆、对话和工具调用。
- 模型供应商、模型密钥、调用额度和审计日志。
- Web 端村民管理界面。

现有 `playerCanStand` 和 `shared_town_player_can_stand` 只能解决“某个位置是否能站立”。它们需要扩展为“从一个位置到另一个位置是否存在合法转换，以及需要什么能力”。

## 4. 核心设计原则

### 4.1 素材外观和游戏语义分离

AI 不应该只根据 PNG 判断河流、悬崖或楼梯。每个素材发布时必须有结构化的导航属性。可以让视觉模型辅助建议标签，但必须由管理员确认后才能发布。

### 4.2 AI 负责意图，服务器负责规则

Agent 可以提出：

- 去农田工作。
- 和某个村民交谈。
- 搬运桥梁材料。
- 使用梯子爬上悬崖。

服务器负责验证：

- 是否存在合法路线。
- 村民是否拥有所需道具。
- 道具是否可以在当前地形使用。
- 当前地图版本是否发生变化。
- 行动是否与其他玩家或村民冲突。

模型不能直接写数据库、修改坐标或绕过碰撞检查。

### 4.3 事件驱动，而不是每帧调用模型

地图模拟每 1～5 秒更新一次，但只有发生重要事件时才唤醒 Agent，例如到达工作时间、遇到村民、路线失效、任务完成、天气改变或收到玩家指令。这样可以控制成本和延迟。

## 5. 村民领域模型

### 5.1 基本资料

```text
NpcDefinition
├── npc_id
├── display_name
├── appearance_asset_id
├── portrait_asset_id
├── enabled
├── home_zone
├── occupation
└── owner_scope / public_scope
```

### 5.2 人格和背景

人格不保存为一段不可管理的长提示词，而是保存为结构化字段：

```text
NpcProfile
├── traits                 性格特征，例如谨慎、热心、固执
├── values                 价值观，例如家庭、效率、荣誉
├── fears                  恐惧或回避事项
├── speech_style           语气、词汇和称呼方式
├── family_background      家庭成员、成长经历、经济背景
├── short_goals            短期目标
├── long_goals             长期目标
├── secrets                可选的隐藏信息
└── behavior_constraints   不允许执行的行为
```

服务器根据这些字段生成系统提示词。管理员修改字段后，新的会话使用新配置；已经形成的长期记忆不应自动删除。

### 5.3 日程和任务

日程使用结构化时间段：

```json
{
  "weekday": 1,
  "from": "08:00",
  "to": "12:00",
  "activity": "work",
  "target_zone": "farm-zone-3",
  "priority": 80
}
```

任务使用状态机：

```text
planned → assigned → moving → executing → completed
                         └────── failed / blocked / cancelled
```

任务必须有超时、重试次数和失败原因，不能让 Agent 无限重复同一动作。

## 6. 动态通行和素材语义

### 6.1 素材导航属性

建议在 `town_asset_items` 增加 `navigation_json`，示例：

```json
{
  "kind": "cliff",
  "blocks_walk": true,
  "elevation": 1,
  "solid_regions": [
    {"x": 0, "y": 0, "width": 2, "height": 1}
  ],
  "transitions": [
    {
      "from": "south",
      "to": "north",
      "requires": ["ladder"],
      "cost": 4
    }
  ],
  "interaction_points": [
    {"side": "south", "x": 1, "y": 0}
  ]
}
```

标准素材类型至少包括：

| 类型 | 默认规则 |
| --- | --- |
| `plain` | 正常行走 |
| `road` | 正常行走，移动成本较低 |
| `water` | 默认阻挡 |
| `bridge` | 允许跨越水域 |
| `cliff` | 默认阻挡，需要爬升能力 |
| `stairs` | 连接不同高度，通常不消耗道具 |
| `wall` | 阻挡 |
| `door` | 根据开放状态和权限判断 |
| `workstation` | 可到达但有工作交互点 |
| `decoration` | 依据碰撞属性判断 |

旧素材没有 `navigation_json` 时，默认按现有 `footprint_json` 和 `collision_json` 作为阻挡物处理，避免未知素材被 AI 当成可通行区域。

### 6.2 道具能力

道具需要声明能力，而不是只保存名称：

```json
{
  "item_id": "ladder",
  "capabilities": ["climb_cliff"],
  "usable_on": ["cliff"],
  "charges": 1,
  "consumable": false
}
```

其他例子：

```text
bridge_kit  → cross_water, build_bridge
boat         → cross_water
axe          → clear_vegetation
key          → open_door
torch        → enter_dark_area
```

### 6.3 通行工具接口

Agent 只能调用服务器提供的工具：

```text
can_traverse(from, to, actor_id, inventory)
find_route(start, target, actor_id, inventory)
required_capability(obstacle_id, actor_id)
inspect_area(center, radius)
reserve_interaction_point(point_id, actor_id)
use_item(actor_id, item_id, target_id)
```

返回结果示例：

```json
{
  "allowed": false,
  "blocked_by": "cliff-001",
  "required_item": "ladder",
  "has_item": false,
  "alternative_route": true,
  "navigation_version": 42
}
```

## 7. 寻路和地图动态更新

### 7.1 第一版算法

768×768 地图可以先使用分区网格 A*：

- 以 24×24 区块作为局部寻路范围。
- 区块内部使用网格 A*。
- 区块之间建立高层连接图。
- 楼梯、桥、门和悬崖转换点作为连接边。
- 路线成本包含地形成本、拥堵成本、道具消耗和危险系数。

不用在第一版引入完整 3D 导航系统。只有地图变成真正的多高度 3D 场景时，才考虑 Recast/Detour。

### 7.2 更换素材后的流程

```text
管理员修改素材
    ↓
PHP API 保存导航属性
    ↓
计算受影响素材和区块
    ↓
更新 navigation_version
    ↓
发布 nav_changed 事件
    ↓
NPC Agent 清除路线缓存
    ↓
正在移动的 NPC 重新规划
    ↓
客户端收到 NPC 状态和路线变化
```

地图版本变化时，所有路线必须携带 `navigation_version`。服务器发现版本不一致后拒绝继续执行旧路线，避免村民继续走入新出现的悬崖或河道。

### 7.3 行走中的特殊情况

- 路线被玩家放置的物件堵住：停止并重新规划。
- 所需道具被其他村民拿走：进入 `blocked` 状态，等待资源或改走其他路线。
- 梯子被删除：撤销跨悬崖路线。
- 河道被改宽：重新生成跨越点。
- 玩家正在占用交互点：排队或选择备用点。
- NPC 走到旧地图版本：服务器返回 `navigation_conflict`，客户端重新同步。

## 8. Agent 系统

### 8.1 Agent 层次

```text
World Scheduler
    ↓
Npc Planner
    ↓
Task Executor
    ↓
Movement / Work / Talk / Item Tools
```

- `World Scheduler`：处理时间、天气、节日和全局事件。
- `Npc Planner`：根据人格、目标、日程和关系选择下一件事。
- `Task Executor`：执行任务状态机，管理超时和重试。
- 工具层：只允许白名单动作，并由服务器验证。

### 8.2 AI 返回格式

所有模型调用都要求 JSON Schema，禁止直接返回自由文本动作：

```json
{
  "intent": "work",
  "target_id": "farm-zone-3",
  "required_capabilities": [],
  "steps": [
    {"action": "walk", "target": "farm-zone-3"},
    {"action": "work", "job": "water_crop", "duration_minutes": 20}
  ],
  "reason": "今天早上的农田需要浇水"
}
```

模型输出必须经过：

1. JSON Schema 校验。
2. 角色权限校验。
3. 地图通行校验。
4. 道具和资源校验。
5. 任务锁和并发校验。
6. 执行超时和预算校验。

### 8.3 NPC 对话

村民对话不应持续高频调用模型。推荐流程：

1. 由附近、关系、任务或玩家行为触发对话候选。
2. 根据优先级筛选真正需要生成的对话。
3. 注入双方的关系、近期记忆和当前环境。
4. 生成有限轮次对话。
5. 保存摘要、情绪变化和关系变化。
6. 超过上下文长度时只保留摘要和重要记忆。

村民之间的“协同工作”通过任务和资源状态实现，而不是让多个模型自由聊天后自行修改世界。

## 9. 数据库设计

建议增加以下表：

```text
town_npcs
  npc_id, display_name, appearance_asset_id, portrait_asset_id,
  occupation, home_zone, enabled, version, created_at, updated_at

town_npc_profiles
  npc_id, traits_json, values_json, fears_json, speech_style_json,
  family_background_json, goals_json, constraints_json, updated_at

town_npc_schedules
  npc_id, weekday, start_time, end_time, activity,
  target_zone, priority, enabled

town_npc_relationships
  npc_id, other_npc_id, relation_type, affinity, trust,
  rivalry, notes_json, updated_at

town_npc_memories
  memory_id, npc_id, memory_type, summary, importance,
  embedding_ref, source_event_id, created_at, expires_at

town_npc_runtime_states
  npc_id, tile_x, tile_y, pixel_x, pixel_y, state,
  current_task_id, route_json, navigation_version, inventory_json,
  last_tick_at, updated_at

town_npc_tasks
  task_id, task_type, owner_npc_id, target_id, status,
  priority, prerequisites_json, assigned_at, deadline_at,
  failure_reason, updated_at

town_npc_events
  event_id, world_id, event_type, source_id, target_id,
  payload_json, navigation_version, created_at

town_agent_runs
  run_id, npc_id, trigger_type, model_provider_id, model_name,
  input_hash, output_json, tokens_in, tokens_out, latency_ms,
  status, error_message, created_at

town_model_providers
  provider_id, name, kind, base_url, model_name,
  secret_ref, max_tokens, temperature, timeout_seconds,
  enabled, created_at, updated_at
```

在 `town_asset_items` 增加：

```text
navigation_json
navigation_version
```

在 `town_worlds` 增加：

```text
navigation_version
```

不建议把完整对话文本写入 `town_change_log`。地图变化和 NPC 事件应分开保存，避免普通地图同步被大量对话记录拖慢。

## 10. API 和客户端桥接

### Web 管理 API

```text
GET    /api/admin/npcs
POST   /api/admin/npcs
PUT    /api/admin/npcs/{npc_id}
DELETE /api/admin/npcs/{npc_id}
POST   /api/admin/npcs/{npc_id}/pause
POST   /api/admin/npcs/{npc_id}/resume
POST   /api/admin/npcs/{npc_id}/reset-memory
POST   /api/admin/npcs/{npc_id}/assign-task

GET    /api/admin/models
POST   /api/admin/models/test
PUT    /api/admin/models/{provider_id}

GET    /api/admin/town-assets/{asset_id}/navigation
PUT    /api/admin/town-assets/{asset_id}/navigation
POST   /api/admin/town/navigation/rebuild
GET    /api/admin/town/navigation/preview
```

### 小镇运行 API

```text
GET  /api/v2/town/npcs/snapshot
GET  /api/v2/town/npcs/changes?cursor=...
POST /api/v2/town/npcs/action
GET  /api/v2/town/navigation/query
```

客户端桥接消息建议增加：

```text
npcSnapshotRequested
npcChangesRequested
npcActionRequested
npcSnapshot
npcChanges
npcActionResult
navigationVersionChanged
```

NPC 客户端只需要渲染权威状态、播放动画和显示对话气泡，不运行模型，也不拥有最终规则。

## 11. Web 管理界面

建议增加“村民管理”和“模型配置”两个页面。

### 村民管理

- 村民列表：姓名、职业、状态、当前位置、当前任务、最后一次 Agent 调用。
- 基本资料编辑：姓名、外观、头像、职业和家庭区域。
- 人格编辑：特征、价值观、恐惧、说话方式。
- 背景和目标：家庭背景、短期目标、长期目标。
- 日程编辑：时间轴式表格。
- 关系编辑：村民之间的关系和好感度。
- 运行控制：暂停、恢复、强制移动、分配任务、清除记忆。
- 调试信息：最近路径、阻挡原因、模型输出、失败原因。

### 素材导航编辑

在现有素材编辑页面增加：

- 素材类型。
- 是否阻挡玩家。
- 是否阻挡 NPC。
- 高度或层级。
- 楼梯、门、桥和河道连接方向。
- 所需道具或能力。
- 交互点位置。
- 导航预览和受影响区块预览。

未知导航属性的素材必须显示为“未配置”，并默认不可通过。

### 模型配置

- 供应商名称和类型。
- Base URL。
- 模型名称。
- 温度、最大输出长度和超时。
- 单 NPC、单用户和全局额度。
- 测试连接按钮。
- 启用/停用。

API Key 只能写入服务端受保护的 secret 存储，Web 页面不回显完整密钥。

## 12. 开源组件选择

### 推荐组合

| 组件 | 作用 | 选择理由 |
| --- | --- | --- |
| Semantic Kernel | .NET Agent 编排和工具调用 | 与 DeskBox/C# 技术栈一致 |
| LiteLLM | 统一模型供应商接口 | 可切换云端模型和本地模型 |
| Ollama | 本地开发模型 | 安装简单，适合开发和测试 |
| vLLM | GPU 模型服务 | 适合后续生产吞吐量 |
| PostgreSQL/pgvector | 结构化数据和记忆检索 | 适合统一存储 |
| Grid A* + 区块图 | 2D 地图寻路 | 足够支持当前 768×768 地图 |

### 规模扩大后的选项

- Nakama：需要更强实时同步、房间和多人状态时使用。
- Colyseus：如果后续采用 Node.js 实时房间服务器。
- LangGraph：如果把 Agent 编排独立为 Python 服务。
- Qdrant：NPC 长期记忆量较大时使用向量数据库。
- Recast/Detour：真正出现多高度 3D 导航时使用。

没有一个开源系统可以无修改地同时解决“村民人格、地图素材语义、动态寻路、道具能力、多人同步和现有 DeskBox 集成”。最稳妥的是组合使用，而不是一次性替换现有架构。

## 13. 分阶段开发计划

### 阶段一：导航语义和服务器规则

- 增加 `navigation_json` 和地图导航版本。
- Web 端配置素材类型、碰撞、楼梯、悬崖、河道和道具需求。
- 实现 `can_traverse` 和 `find_route`。
- 将玩家和 NPC 的通行检查统一到服务器。
- 增加地图变更后的导航缓存失效。

验收标准：替换河道、悬崖或楼梯素材后，服务器能正确返回可通行、阻挡原因和所需道具。

### 阶段二：NPC 基础运行时

- 增加 NPC 表、运行状态、日程和库存。
- 客户端显示 NPC、名称、状态和移动动画。
- 实现无 AI 的确定性日程。
- 实现服务器权威移动和路线失效重规划。

验收标准：村民可以按日程在家、工作区和公共区域之间移动，地图修改后不会走旧路线。

### 阶段三：单村民 Agent

- 引入 .NET Worker、Semantic Kernel 和 LiteLLM。
- 实现模型配置、调用额度和 Agent 日志。
- 实现严格 JSON Action Schema。
- 允许 Agent 选择工作、休息、交谈和使用道具。
- 所有行动经过服务器验证。

验收标准：模型故意返回非法位置、非法道具或穿越悬崖时，服务器拒绝执行并记录原因。

### 阶段四：多人格和村民对话

- 增加家庭背景、目标、关系和记忆。
- 增加事件触发的对话系统。
- 增加对话摘要和长期记忆。
- 增加村民之间的任务协同。

验收标准：两个村民可以根据关系和目标产生不同对话，并通过任务状态协同工作。

### 阶段五：管理和运营

- 完成 Web 村民管理页面。
- 增加暂停、恢复、强制任务、重置记忆和模型切换。
- 增加成本、延迟、失败率和阻挡原因监控。
- 增加批量导入、导出和配置版本。

验收标准：管理员可以不修改数据库，完成村民全生命周期管理，并能定位一次 Agent 失败的完整原因。

## 14. 测试要求

### 导航测试

- 普通地面、道路和森林。
- 河道、桥梁、楼梯和悬崖。
- 道具存在、缺失、数量不足和被其他 NPC 占用。
- 地图素材替换后的导航版本变化。
- 路线执行过程中物件被删除或移动。
- 768×768 边缘和跨区块寻路。

### Agent 测试

- 非法 JSON 输出。
- 非法工具参数。
- 模型超时、限流和服务不可用。
- 重复任务和并发任务。
- 记忆过长和记忆冲突。
- NPC 被暂停或账号切换。

### 客户端测试

- NPC 快照、增量变化和旧会话响应拒绝。
- NPC 移动插值和路线取消。
- 地图变更后的 NPC 状态刷新。
- NPC 对话气泡和状态显示不遮挡编辑菜单。

### 线上安全测试

- 普通账号不能修改 NPC 配置。
- Agent 不能直接调用数据库。
- 模型密钥不出现在 WebView、日志和响应中。
- 每个 NPC 和每个用户都有调用额度。
- 所有管理动作和 Agent 动作保留审计记录。

## 15. 主要风险和处理方式

| 风险 | 处理方式 |
| --- | --- |
| 模型让村民穿墙或瞬移 | 服务器权威寻路和行动校验 |
| 素材只配置了图片没有语义 | 未配置素材默认不可通行 |
| 每个村民频繁调用模型导致成本高 | 事件触发、冷却时间、预算和批量决策 |
| 地图频繁变化导致路线失效 | `navigation_version` 和区块级缓存失效 |
| 多个村民抢同一工作点 | 交互点预约和任务锁 |
| 对话内容无限增长 | 近期记忆加长期摘要 |
| PHP 请求被 AI 调用阻塞 | Agent 使用独立 Worker 队列 |
| Agent 服务故障影响小镇 | NPC 回退到确定性日程，玩家地图继续可用 |
| 本地模型质量不稳定 | 模型网关支持按任务切换云端模型和本地模型 |

## 16. 第一版建议范围

第一版不要同时实现所有复杂能力，建议固定为：

- 5～10 个村民。
- 3 种职业：农夫、商人、工匠。
- 4 种行动：行走、工作、休息、交谈。
- 4 种导航语义：普通地面、阻挡物、楼梯、河道。
- 2 种道具能力：过河、爬升。
- 1 个模型网关和 1 个 Agent Worker。
- Web 端完成村民 CRUD、人格编辑、日程编辑和模型配置。
- 玩家和服务器共享同一套通行判断。

完成这部分后，再增加家庭关系、长期记忆、复杂协同和更多道具。这样可以先验证“素材更换 → 通行规则变化 → Agent 重新规划 → 村民执行”的完整闭环。

## 17. 当前实现状态与续做记录（2026-11-10）

本节是当前代码、远程数据库和线上 Worker 的事实快照。后续继续开发时先阅读本节，再按“未完成”和“下一步”推进，避免把已经上线的功能重复排查或重复实现。状态分为：

- **已完成**：代码已实现，并有本地测试或线上证据。
- **部分完成**：主链路可运行，但仍缺少方案中列出的完整运营能力。
- **未完成**：只有数据库预留或设计，尚未形成可用闭环。

### 17.1 总体状态表

| 模块 | 状态 | 已完成内容与证据 | 当前边界 / 下一步 |
| --- | --- | --- | --- |
| 数据库迁移 | 已完成 | `database/028_shared_town_npc_v1.sql` 和 `database/029_town_agent_observability.sql` 已执行到远程 MySQL。原有 11 张 NPC/Agent 表继续保留，029 新增 `town_npc_activity_logs`，并为运行状态增加 `mood_json`，为 `town_agent_runs` 增加模型、总 Token、耗时、行动、原因和结构化输出字段。 | 关系、记忆、费用单价和额度扣减仍未形成完整业务闭环。继续开发时优先复用这些表，不要再次创建同名表。 |
| 素材导航语义 | 已完成 | `api/_app/town_navigation.php` 支持 `plain`、`blocked`、`stairs`、`water`、`bridge`；`can_traverse`、网格路线查询和导航版本已接入服务端。素材管理页可保存导航 JSON，未知语义默认按阻挡处理。线上素材发布会产生新的素材版本。 | 目前是首版二维网格规则；楼梯、桥梁的复杂连接点、交互点预约、道具库存扣除仍未完整实现。 |
| 导航 API | 已完成 | `GET /api/v2/town/navigation/query.php` 和 `POST /api/v2/town/navigation/rebuild.php` 已部署；NPC 行走动作会在服务端调用路线检查，不能由模型直接写坐标。 | 还没有把导航查询结果做成 Worker 的长期路线缓存和地图变更后的主动重规划队列；当前由服务端在动作执行时重新校验。 |
| NPC 基础 API | 已完成 | `npcs/snapshot.php`、`npcs/changes.php`、`npcs/actions.php`、`npcs/manage.php` 已实现。普通用户只能读取自己的小镇快照，NPC 行动必须使用 Worker 服务令牌和租约；动作会写入 `town_npc_tasks`、`town_npc_runtime_states`、`town_npc_events`。 | 管理 API 已有基础 upsert/toggle 能力，但 Web 页面还没有完整的逐项编辑器、关系编辑器、记忆清除和强制任务表单。 |
| Worker 租约 | 已完成 | `src/DeskBox.NpcWorker/Program.cs` 实现全局 `main` 租约：TTL 30 秒、约 10 秒续租；失去租约后不应继续写入。线上容器 `deskbox-npc-worker` 的租约请求持续返回 200，重启次数为 0。 | 尚未部署多 Worker 并发接管的压力测试；当前线上只运行一个实例。 |
| Worker 日程回退 | 已完成 | Worker 拉取 NPC 快照后按日程生成 `walk/work/rest/talk` 动作；模型为空、超时或非法 JSON 时回退到确定性日程。线上三个样例村民已经产生 `state_changed` 事件并写入运行状态。 | 日程现在是固定时间段，不包含天气、节日、前置任务、失败重试和任务锁。 |
| OpenAI 兼容模型调用 | 部分完成 | Worker 支持 OpenAI 兼容 `chat/completions`，读取 `model`、`temperature`、超时和 JSON 动作；模型响应中的输入/输出/总 Token、耗时、状态、原因和结构化行动摘要已写入 `town_agent_runs`，线上 `deskbox-npc-worker:v5` 已产生记录。模型还可返回 `dialogue`、`mood` 和 `steps`，服务端只执行允许的行动类型。 | 尚未实现费用单价配置、月额度强制扣减和完整 steps/capability 前置条件校验。 |
| Web 模型配置 | 已完成 | 管理后台 `?panel=town_agent` 页面现在同时提供模型配置、按人物/时间范围筛选、调用次数、输入/输出/总 Token、平均耗时和详细活动日志；日志类型包含 `agent_run`、`action`、`speech`、`path`、`mood`。API Key 通过服务端加密后写入 `town_model_providers`，浏览器不回显原文；Worker 通过服务身份读取。 | 当前保留服务器 `.env` 作为兼容回退。若 Web 页面没有保存并启用 provider，Worker 仍使用 `.env`；月额度字段已保存但尚未强制扣减，也没有单价配置。 |
| NPC 客户端显示 | 已完成 | `SharedTownSession` 和 Phaser 小镇页面已接入 NPC 快照、增量游标、精灵、姓名、状态和对话气泡；`renderNpcs()` 会优先加载 `avatar_asset_id` 对应图集，服务端坐标变化会触发 Phaser tween 移动，工作/交谈状态有轻微动作反馈；客户端只渲染服务端状态，不负责最终碰撞和寻路。 | 仍未实现完整的四方向动作帧状态机、路线逐格回放和对话历史面板；当前 tween 是首版视觉插值。 |
| 样例人物素材 | 已完成 | 从本地 `星露谷物语素材/Stardew valley/Characters` 选取 `Alex`、`Caroline`、`Shane` 角色图集，发布为 `town-source-npc-alex`、`town-source-npc-caroline`、`town-source-npc-shane`；每个素材使用 16×32 帧、32×64 显示尺寸和 4 帧首行动画。素材发布版本为线上第 8 版，旧版本仍保留可回滚。 | 目前使用原始角色图集，没有单独制作 NPC 头像、四方向动画裁剪和服装编辑器；后续可把外观拆成头像、身体、服装、配色字段。 |
| 三个样例村民 | 已完成 | 线上已写入：林野（农夫，Alex 样式）、花青（商人，Caroline 样式）、周岩（工匠，Shane 样式）。三人均有完整人格、价值观、恐惧、说话方式、家庭背景、短期目标、长期目标和 7 天日程；每人 21 条日程记录。修复后线上坐标已分别到达 `farm-zone-3=(128,120)`、`market-zone-1=(96,96)`、`workshop-zone-2=(112,96)`，任务 payload 中已记录 A* 路线。 | 样例仍使用固定演示目标区块；目标区块尚未配置完整的工作交互点，因此主要验证“配置 → 调度 → 路线校验 → 坐标更新 → 客户端显示”链路。 |
| 线上部署 | 已完成 | 029 迁移已在远程 MySQL 执行，新增 `town_npc_activity_logs` 和运行心情字段；后台、活动日志 API、运行记录 API 已同步并通过 PHP 8.2 语法检查。Worker 镜像已更新为 `deskbox-npc-worker:v8`，容器 `restart unless-stopped`、运行中、重启次数为 0；线上已有模型调用、action、speech、mood 记录，provider ID、Token 和完成状态均已写入，最近接口均返回 200。WebSocket 代理已在 17.8 单独记录。 | 还没有做双 Worker 接管、回滚演练、长时间成本监控和多账号线上验收；这些不能用当前容器启动证明替代。 |

### 17.2 本次新增的三个样例

| 村民 | 职业 | 外观素材 | 人格重点 | 家庭与目标 | 日程样例 |
| --- | --- | --- | --- | --- | --- |
| 林野 | 农夫 | `town-source-npc-alex` | 热情、行动派、乐观；重视勤劳、互助和土地 | 农场家庭背景；短期照料农田，长期把荒地改为公共农场 | 上午浇水，中午休息，下午清理杂草；每天重复，便于测试 Worker |
| 花青 | 商人 | `town-source-npc-caroline` | 细心、健谈、好奇；重视公平交易和邻里关系 | 花圃家庭背景；短期整理集市，长期建立跨村物资交换集市 | 上午整理货物，中午在广场交谈，下午服务顾客 |
| 周岩 | 工匠 | `town-source-npc-shane` | 沉稳、可靠、寡言；重视耐用和技术传承 | 木匠与修理铺家庭背景；短期修复设施，长期建立共享工坊 | 上午修理工具，中间休息，下午制作家具 |

三个样例的固定 ID 是：

```text
8cbb3e8f37e8f5bb9b3a0123d1a1a001  林野
8cbb3e8f37e8f5bb9b3a0123d1a1a002  花青
8cbb3e8f37e8f5bb9b3a0123d1a1a003  周岩
```

后续修改样例时优先通过 Web 管理页完成；如果必须执行 SQL，应使用这些 ID 做精确更新，避免按姓名匹配。删除样例前先暂停 Agent，并确认没有需要保留的事件记录。

### 17.3 已验证的闭环

当前已经验证的最小闭环如下：

```text
人物素材图集
    ↓
town_asset_items / town_asset_releases
    ↓
NPC avatar_asset_id + 人格/背景/目标/日程
    ↓
Worker 获取全局租约
    ↓
Worker 获取 NPC 快照和模型配置
    ↓
模型返回动作；失败时使用确定性日程
    ↓
服务器校验 NPC 动作并写入任务、运行状态、事件
    ↓
SharedTownSession 增量同步
    ↓
Phaser 显示人物、姓名、状态和对话气泡
```

已保存的线上证据包括：

- 三个样例 NPC 均为 `enabled=1`、`agent_paused=0`。
- 三个样例各有 21 条按周重复的日程。
- 三个样例的运行状态已更新为 `work`，位置由服务器维护并已移动到各自目标；最新任务的 `target_id` 和 `payload_json.route` 均已保存。
- 最近的 `town_npc_events` 中出现三人的 `state_changed` 事件。
- Worker 的模型请求和 NPC 动作请求均返回 HTTP 200。

### 17.4 尚未完成的核心能力

以下项目不要误认为已经完成，即使数据库中已经有对应表或字段：

1. **完整 NPC 编辑**：当前 Web 页可以新增、停用、暂停和删除，完整的逐项编辑、关系编辑、记忆清除、强制任务和运行调试面板仍需补齐。
2. **关系、记忆和对话沉淀**：表已创建，但没有关系好感度计算、长期记忆摘要、记忆检索和对话摘要流程。
3. **道具和库存能力**：导航语义支持所需能力字段，但 NPC 专属库存、梯子/桥梁消耗、资源锁和交互点预约尚未形成完整事务。
4. **Agent 运行审计**：模型调用记录、输入/输出 Token、总 Token、耗时、行动摘要、失败原因已写入 `town_agent_runs`；NPC 的 action、speech、path、mood 和 agent_run 已写入 `town_npc_activity_logs`，后台 `town_agent` 页面支持按人物和 1/7/30/90 天查看。费用单价、费用总额和月额度强制扣减仍未完成。
5. **额度和供应商运营**：Web 可以保存月额度和一个 provider，但目前没有额度扣减、超额拒绝、连接测试、供应商切换历史和成本报表。
6. **严格行动协议**：当前动作已限制为 `walk/work/rest/talk`，但还没有完整校验方案中的多步骤 `steps`、能力声明、任务前置条件和最大重试次数。
7. **复杂动态寻路**：基础路线和导航版本已经可用，但尚未实现区块级路线缓存、路线失效事件、跨高度连接、桥梁建造和被占用交互点排队。
8. **长期线上验收**：尚未完成双 Worker 租约接管、回滚演练、多账号切换、模型超时/额度耗尽和客户端视觉逐项验收。

### 17.5 NPC 不移动问题的诊断与修复（2026-11-10）

用户首次测试时看到的现象是“人物放置后有状态变化，但不会自己走动”。排查结果和修复链路如下：

1. **现象证据**：线上 Worker 的租约、NPC 快照、模型请求和动作接口均返回 200；`town_npc_events` 持续写入 `state_changed`，说明调度循环没有停止。
2. **第一处原因**：`town_npcs.php` 原来只在 `walk` 动作中解析目标并调用 `town_navigation_find_route()`；`work` 和 `talk` 只更新状态，坐标保持原值。
3. **第二处原因**：Worker 的模型计划把 `target_id` 放在 `PlannedAction` 中，但发送 API 时只发送 `Payload`，模型返回的目标因此丢失。
4. **修复内容**：
   - `work`/`talk` 现在和 `walk` 一样经过服务端寻路、能力检查和导航版本校验，并将路线写入任务 payload。
   - Worker 发送动作时会把 `TargetId` 合并进 payload；模型没有目标时，会从 NPC 的已配置日程补充同类动作的目标。
   - 服务端在 Worker 未提供目标时，会从该 NPC 的日程表选择已配置目标，作为最后的确定性兜底；没有可达路线时返回 `no_route`，不会瞬移。
   - `town.js` 的 `renderNpcs()` 使用 NPC 素材并对坐标变化执行 Phaser tween；工作/交谈状态显示轻微动作，保留姓名和状态气泡。
5. **验证证据**：PHP 语法检查和 `town_npc_navigation_test.php` 的 9 项检查通过；Worker Debug 构建 0 警告 0 错误；线上 `deskbox-npc-worker:v4` 运行中且重启次数为 0；三个样例坐标已经从 `120,112`、`136,112`、`152,112` 移动到 `128,120`、`96,96`、`112,96`，最新任务 payload 含完整 A* 路线并返回 HTTP 200。

**继续测试时的判断方式**：先看 `town_npc_runtime_states.tile_x/tile_y` 是否变化，再看 `town_npc_tasks.payload_json.route` 和 `town_npc_events`；数据库坐标已变化但画面不动时，才进入客户端会话/增量同步和 WebView 缓存排查。当前本地 canonical Debug 实例已重启，路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。

### 17.6 续做顺序

建议按以下顺序继续，不要同时扩展多个未完成面：

1. 先补 Web 村民编辑、日程表格、关系和运行状态调试，确保管理员不需要直接改数据库。
2. 再补 `town_agent_runs` 写入、额度扣减、模型连接测试和失败回退原因展示。
3. 接着实现 NPC 库存、道具能力、交互点预约和任务锁，把“需要梯子/桥梁”从字段升级为可执行事务。
4. 再实现导航版本变化事件、路线缓存失效和被阻挡 NPC 的重新规划。
5. 最后增加关系、长期记忆、对话摘要和多人协同，避免在基础执行链路尚未可观测时扩大 Agent 行为范围。

### 17.7 续做时的固定检查清单

每次继续本方案时，先检查：

```text
[ ] 远程 town_npcs 是否仍有 3 个样例，是否被暂停或删除
[ ] town_asset_releases 当前发布版本和活动 release 是否一致
[ ] Worker 容器是否 running，restart_count 是否为 0
[ ] Worker 是否能取得 lease、snapshot、model 配置和 actions 的 200 响应
[ ] 最近 town_npc_events 是否持续产生，是否出现 no_route / npc_paused / lease_required
[ ] Web 模型页面是否已保存 provider；没有 provider 时确认 .env 回退仍符合预期
[ ] 修改地图后 navigation_version 是否增加，旧路线是否被服务端重新校验
[ ] 先运行 PHP 目标测试和 Worker build，再做线上同步
```

本节记录的是 2026-11-10 的状态；后续每完成一项，更新本表的“状态、证据、边界”，并保留旧发布版本和数据库事件，不要用一句“已完成”覆盖具体验证信息。

### 17.8 WebSocket 实时通道（2026-11-11）

| 项目 | 状态 | 已完成内容与证据 | 当前边界 / 下一步 |
| --- | --- | --- | --- |
| Worker WebSocket 宿主 | 已完成 | `src/DeskBox.NpcWorker/Program.cs` 使用 ASP.NET Core Kestrel，同时运行 NPC 后台循环和 `/ws/town` WebSocket 路由；`/health` 返回 `{\"ok\":true,\"service\":\"npc-worker\"}`。本地 Worker Debug 构建 0 警告、0 错误。 | 线上仍需要有用户登录令牌的客户端做一次完整握手和广播验收。 |
| WebSocket 鉴权与转发 | 已完成 | `TownRealtimeHub` 要求 `Authorization: Bearer`，连接前通过 `me.php` 和 `world.php` 验证；玩家 `presence` 先转发到 PHP 权威接口，成功后广播给所有连接；在线未登录握手经 Nginx 返回 401，普通 HTTP 访问返回 426。 | 目前没有独立连接数、消息速率和慢客户端指标；后续可增加连接统计和广播丢弃计数。 |
| NPC 实时事件 | 已完成 | Worker 执行动作成功后广播 `npc_state`，包含服务端校验后的状态、坐标、路线、对话、心情和 `navigation_version`；PHP `town_npcs.php` 已同步完整动作返回字段。线上 `deskbox-npc-worker:v8` 运行中，健康检查 200、重启次数 0，租约/快照/模型接口持续返回 200，修复后日志不再出现广播状态异常。 | 线上尚未用两个真实登录客户端进行同屏人工验收；NPC 仍由单 Worker 推进。 |
| DeskBox 客户端接入 | 已完成 | `SharedTownSession` 建立带令牌的 `ClientWebSocket`，接收实时消息并在会话切换、关闭时释放；`TreeTownPrototypeWindow` 将事件桥接到 WebView；`town.js` 增加 `realtime` 状态、`applyRealtimeNpc` 和实时玩家列表更新。连接断开会恢复 HTTP presence/NPC 轮询。 | WebSocket 地址默认是 `wss://sltbox.2leaves.cn/ws/town`，可用 `DESKBOX_TOWN_REALTIME_URL` 覆盖；公网双账号视觉验收仍待完成。 |
| 线上容器和代理 | 已完成 | Worker 镜像已更新为 `deskbox-npc-worker:v8`，容器监听宿主 `127.0.0.1:28080`；Nginx 增加 `/ws/town` 到 `127.0.0.1:28080/ws/town` 的 Upgrade/Connection 代理，`nginx -t` 通过并已 reload。旧容器保留为 `deskbox-npc-worker-v7` 供回滚。 | 端口和旧容器清理需在确认线上验收后再做；不要删除回滚容器前的镜像。 |
| 本地和回退验证 | 已完成 | `node --check town.js`、`verify-town-prototype.mjs`、PHP NPC 导航 9 项测试、DeskBox Debug 构建均通过；当前 canonical Debug 进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。 | 尚未执行完整多账号在线移动、断线重连和 NPC 路线逐格动画的人工验收。 |

实时链路的执行边界固定为：客户端只发送玩家位置和展示事件，Worker 只发送 NPC 意图执行结果，PHP 继续负责登录、碰撞、寻路、道具和数据库写入。WebSocket 不可用时不得阻塞小镇打开，客户端必须继续使用 HTTP 快照和增量轮询。
