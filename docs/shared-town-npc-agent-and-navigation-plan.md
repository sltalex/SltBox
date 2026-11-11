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
| 样例人物素材 | 已完成 | 从本地 `星露谷物语素材/Stardew valley/Characters` 选取 `Alex`、`Caroline`、`Shane` 角色图集，发布为 `town-source-npc-alex`、`town-source-npc-caroline`、`town-source-npc-shane`；每个素材使用 16×32 帧、32×64 显示尺寸和 4 帧首行动画。2026-11-11 已从当前草稿重新合并并发布线上第 12 版，发布后回读到 5 项素材、4 张图集，三套 NPC 图集文件均存在且 SHA-256 一致；发布前后配置备份保存在服务器 `/root/deskbox-backups/npc-assets-publish-20261111-103802/`。 | 目前使用原始角色图集，没有单独制作 NPC 头像、四方向动画裁剪和服装编辑器；后续可把外观拆成头像、身体、服装、配色字段。 |
| 三个样例村民 | 已完成 | 线上已写入：林野（农夫，Alex 样式）、花青（商人，Caroline 样式）、周岩（工匠，Shane 样式）。三人均有完整人格、价值观、恐惧、说话方式、家庭背景、短期目标、长期目标和 7 天日程；每人 21 条日程记录。修复后线上坐标已分别到达 `farm-zone-3=(128,120)`、`market-zone-1=(96,96)`、`workshop-zone-2=(112,96)`，任务 payload 中已记录 A* 路线。 | 样例仍使用固定演示目标区块；目标区块尚未配置完整的工作交互点，因此主要验证“配置 → 调度 → 路线校验 → 坐标更新 → 客户端显示”链路。 |
| 线上部署 | 已完成 | 029 迁移已在远程 MySQL 执行，新增 `town_npc_activity_logs` 和运行心情字段；后台、活动日志 API、运行记录 API 已同步并通过 PHP 8.2 语法检查。Worker 镜像已更新为 `deskbox-npc-worker:v8`，容器 `restart unless-stopped`、运行中、重启次数为 0；线上已有模型调用、action、speech、mood 记录，provider ID、Token 和完成状态均已写入，最近接口均返回 200。WebSocket 代理已在 17.8 单独记录。2026-11-11 又完成 NPC 图集草稿合并发布：活动素材版本为 v12，发布 ID 为 `14db7afbc471fcaf5c7848166a711f94`，配置哈希为 `5f0412a958264e3a18c7c411881cf2777760d0c2081d6b56ac23097941b16219`；线上回读确认 5 项素材、4 张图集和全部文件哈希一致。 | 还没有做双 Worker 接管、回滚演练、长时间成本监控和多账号线上验收；这些不能用当前容器启动证明替代。 |

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

2026-11-11 图集发布后的临时运行验收：按授权临时将林野和周岩的 `agent_paused` 从 `1` 改为 `0`，验收结束后已恢复为 `1`。变更前状态、运行期间查询结果和恢复结果备份在服务器 `/root/deskbox-backups/npc-agent-acceptance-20261111-104151/`。运行期间林野产生了 `agent_run`、`action`、`state_changed`、`task_completed` 记录并完成农田任务；周岩产生了模型运行开始/失败记录，未改变坐标或暂停状态。恢复后两人均为 `enabled=1、agent_paused=1`，当前位置仍分别为 `(128,120)` 和 `(112,96)`。这次验收证明临时恢复、Worker 写入和安全恢复链路可用，但没有把样例人物的长期 Agent 状态改为启用。

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

## 18. 持续实施阶段与验收账本（2026-11-11 起）

用户已授权按本方案分阶段实施至完成；涉及玩法、成本或业务规则需要用户判断时，先交互取得结论，再进行依赖该结论的工作。原方案目标保持不变，下面的阶段是实施顺序，不是缩减范围。第 17 节保留的是历史事实；存在差异时以本节更晚的验证记录和当前代码为准。

| 阶段 | 当前状态 | 必须交付和验收 |
| --- | --- | --- |
| 1. 实时同步可靠性 | 实施中；本地协议测试通过 | 玩家像素坐标 10Hz 同步；PHP 逐次位置校验；通常 1Hz 持久化和停止/关闭强制保存；单人增量广播；自动重连、重新取令牌、补快照、旧会话隔离；分片和坏消息处理；慢连接隔离；HTTP 回退；双客户端运行验证。 |
| 2. NPC 权威执行与调度 | 首轮已部署；持续实施中 | 服务端持久化执行中的任务和路线进度，按时间推进位置，到达后开始工作/交谈；独立续租，失租停止写入；慢模型不阻塞其他 NPC；有界重试与防重复；无模型/超时/非法输出回退；日程时区一致；地图导航版本变化时停止旧路线并重新规划。 |
| 3. 后台与世界工作点 | 进行中；管理切片已部署 | 已有村民可编辑资料、人物素材、位置、暂停/恢复、强制任务、清除记忆和表格日程；详细运行状态和日志已有基础页面。地图真实工作点、关系编辑和附近设施上下文仍未完成。 |
| 4. 动态通行、道具与工作 | 待实施 | 玩家/NPC 使用一致导航；楼梯方向和高度转换；河道/桥梁；NPC 库存和能力、梯子/桥梁使用与消耗；交互点预约、资源锁；农田工作产生实际持久化结果；途中新增/更换素材后的重规划。 |
| 5. 社交与模型运营 | 待实施 | 不同人格的双向对话、关系变化、记忆写入/摘要/检索和基本协作；模型连接测试、费用单价/报表、额度实际限制；模型凭据保持仅服务端可用。 |
| 6. 完整第一版验收 | 待实施 | 至少 5 个不同人格/日程村民；农田工作、至少两人不同风格对话、河道/阻挡/楼梯、缺道具绕路或等待、动态地图路线失效；Docker 启停和健康检查、无密钥运行、失租接管；多用户及现有编辑/层级功能不回归；记录线上证据和回滚步骤。 |

### 18.1 阶段 1 具体改动

- 新增 `src/DeskBox/Services/TownRealtimeConnection.cs`：单会话连接生命周期、2～30 秒退避重连、重新获取有效访问令牌、心跳、分片文本重组、256KiB 收包限制、发送超时和保存回执。账号更换/关闭取消生命周期，桥接消息携带发起时的 epoch，界面排队处理后再次检查 epoch，不能把旧账号消息标成新账号。
- `DeskBoxAuthSession.GetRealtimeAccessTokenAsync()` 在鉴权操作锁下检查令牌到期时间，临近到期先刷新；WebView 不接触令牌。相同账号的鉴权事件不会再无条件断开连接。
- `TownRealtimeHub` 连接时补 NPC/在线玩家快照；`presence_delta` 只广播 PHP 响应中的 `self`，不能信任客户端伪造的 user_id，也不能把某一玩家附近的名单当成所有人的完整名单。快照读取期间缓存增量，快照发送后依序续发。每个连接使用独立的 128 条有界发送队列，慢连接超时或队列满时断开，NPC 主循环不等待慢连接。
- 玩家移动 WebSocket 上报间隔改为 100ms，HTTP 回退保持 1 秒；传递小数像素坐标并插值。每个上报仍经 PHP 站立位置校验；Hub 通常每 1 秒请求持久化，其余请求使用 transient 只校验。停止移动强制保存，关闭窗口要求服务器保存回执；返回 HTTP 快照时补齐像素精度，不需要新增数据库表。
- 新增 `town-realtime.js`：合并单人增量、过滤重复/倒序消息、按 expires_at 清除掉线玩家；WebSocket 期间忽略尚未返回的旧 HTTP 名单/状态响应，避免覆盖实时结果。
- 修复 NPC 路线动画的 Phaser API：本项目附带版本有 `tweens.chain`，没有旧的 `createTimeline`。本阶段只修正显示链路；真正的服务端途中位置和工作生命周期属于阶段 2，不能据此标成已完成。

### 18.2 本地验证证据与尚未验收项

- 新建跨平台 `tests/DeskBox.NpcWorker.Tests`，使用真实本机 Kestrel 与 ClientWebSocket、替身 PHP HTTP 响应，7 项集成测试通过：无权限拒绝、分片接收/防冒用/像素精度、瞬时位置与最终保存回执、NPC 路线与对话广播、重连取新令牌和快照、首次握手失败重试及取消、非法位置拒绝且不影响其他连接。该测试不连接或修改生产账号。
- `node scripts/verify-town-realtime.mjs` 通过：远处玩家不被增量清除、像素坐标保留、重复/倒序过滤、过期清理、旧 HTTP 格子坐标兼容。
- `php server/deskbox-api/tests/town_player_positions_test.php` 通过：底层小镇测试 32 项、位置测试累计 53 项；新增检查覆盖 transient 不写持久化位置、transient 仍检查阻挡、HTTP 快照小数像素精度。
- Worker Debug 构建通过。客户端 Debug 构建、线上文件回读/容器验证和两端真实画面验收另行记录，不能用上述替身 PHP 测试代替。
- 本阶段未声称解决穿越途中障碍、玩家/NPC 导航语义统一、NPC 服务端执行进度、多人压力测试；这些分别需要阶段 2/4/6 的证据。

### 18.3 阶段 2 首轮实现（2026-11-11）

阶段 2 已进入实施，当前已完成本地服务端和 Worker 的第一段闭环：

- `town_npc_apply_action()` 不再把 NPC 坐标直接写成目标坐标。它会先保存服务器校验过的完整路线、`route_cursor=0`、导航版本、任务时长和 `started_at`，任务状态为 `running`，运行状态仍保持在路线起点。
- 新增 `POST /api/v2/town/npcs/advance.php`。接口只接受 Worker 服务令牌，并要求当前全局租约；每次最多推进路线一格，重新校验下一格是否仍可通行，返回当前坐标、路线游标、剩余路线和任务状态。
- 行走到达终点后才完成 `walk` 任务；`work`、`talk`、`rest` 到达目标后先进入对应状态，达到 `duration_minutes` 后才完成并清除 `current_task_id`。默认工作/交谈/休息时长为 20 分钟，模型或任务可以在允许范围内传入 0～1440 分钟。
- 地图 `navigation_version` 与任务保存版本不一致时，任务进入 `failed`，原因是 `navigation_changed`；途中新增阻挡、非法路线步长和缺少能力也会失败，不会把 NPC 写到未校验位置。
- 每个逐格推进、任务完成和任务失败都会写入 `town_npc_events` 与活动日志，WebSocket 可以广播服务器真实当前位置；客户端的 tween 只是显示插值，不能改变最终位置。
- Worker 快照发现 `current_task_id` 时，先调用 `advance.php` 并广播结果，任务没有完成前不再请求模型或创建新任务。失去租约时，推进请求会被服务器拒绝，Worker 回到重新取得租约的状态。

本地证据：

- `server/deskbox-api/tests/town_npc_task_execution_test.php`：8 项检查通过，覆盖不瞬移、逐格推进、工作持续时间和导航版本失效。
- `server/deskbox-api/tests/town_agent_observability_test.php`、`town_npc_navigation_test.php`：通过，原有日志和导航行为未回归。
- `dotnet test tests/DeskBox.NpcWorker.Tests/DeskBox.NpcWorker.Tests.csproj --no-restore --verbosity:minimal`：7/7 通过。
- `dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore --verbosity:minimal`：0 警告、0 错误。

线上首轮部署证据（2026-11-11）：

- 已先备份 PHP 文件到 `/root/deskbox-backups/realtime-phase2-20261111`，再同步 `town_npcs.php`、`npcs/advance.php`、Worker `Program.cs` 和 `TownRealtimeHub.cs`。
- 远程读回 SHA-256 与本地一致；Web 站点使用 PHP 8.2 检查两份 PHP 文件均无语法错误（服务器 CLI 默认 PHP 7.4，仅作为运维环境提示，不能用它判断 PHP-FPM 8.2 的线上运行版本）。
- Worker 镜像 `deskbox-npc-worker:v10` 已启动，`/health` 返回 200，容器 `restart_count=0`；旧 `v9` 容器已保留为回滚副本。
- 远程 Worker 已实际请求 `advance.php` 并收到 200；当前样例中花青仍被管理员暂停，周岩的推进接口返回 `task_status=none`，因此没有强行改变样例状态。

阶段 2可靠性补强（2026-11-11，已部署）：

- Worker 每轮先推进所有已有任务，再启动新的模型规划；模型规划使用每个 NPC 一个独立的异步 pending 任务，单个模型超时或响应慢不会阻塞其他 NPC 的逐格推进。
- 动作或推进失败按 NPC 记录退避次数：前两次使用 5 秒、10 秒退避，第三次后进入 5 分钟冷却；成功完成或正常无任务时清除失败计数，避免无限重复创建相同任务。
- Worker 丢失租约时会丢弃待执行的旧模型计划；重新取得租约后必须从新快照重新规划，不能把旧租约期间的决定写回服务器。
- 日程时间支持 `TOWN_TIME_ZONE` 配置，非法或未配置时明确回退 UTC；新增时区和确定性回退测试。
- 本地 `DeskBox.NpcWorker.Tests` 已由 7 项增加到 9 项，全部通过；Worker 构建保持 0 警告、0 错误。
- 线上镜像已更新为 `deskbox-npc-worker:v11`；远程 `Program.cs` SHA-256 与本地一致，健康检查 200，重启次数 0。旧 `v10` 已保留，备份目录为 `/root/deskbox-backups/reliability-phase2-20261111`。

阶段 2 仍未完成的部分：

1. 本次阶段 2首轮 PHP 文件已部署并以 SHA-256 读回一致；Worker 镜像已更新为 `deskbox-npc-worker:v10`，健康检查返回 200、重启次数为 0。线上逐格执行和人工画面证据仍需补做，不能用容器健康检查代替。
2. 目前 Worker 的模型调用仍按 NPC 顺序执行，尚未把慢模型决策完全隔离成独立队列；逐格推进已经先于模型决策执行，但模型超时隔离仍要补充。
3. 任务失败后的有限重试、防重复任务、日程时区和暂停后的恢复策略还没有完成。
4. 客户端已能显示实时状态，但还要做线上逐格动画、任务完成和导航变化的人工验收。

因此，阶段 2 完成标准暂时不能标记为“已完成”；下一小步是补齐 Worker 的推进/模型隔离测试并部署本阶段代码，然后进行双账号和 NPC 逐格线上验收。

### 18.4 阶段 3 首个管理切片（2026-11-11）

本次开始阶段 3，先把管理员从“只能新增或开关村民”推进到可以维护运行中的村民：

- `server/deskbox-api/api/admin/index.php` 新增“村民完整资料编辑”区块。已有村民可以编辑姓名、职业、人物素材、当前位置、外观 JSON、人格 JSON、家庭背景、短期目标、长期目标、说话方式、价值观、恐惧和日程；新增可视化日程表，每个时间段可直接选择星期、开始/结束时间、动作、目标、工作内容和优先级。
- 人物素材从当前已发布的 `character`/`npc` 素材列表中选择；如果旧村民引用的素材已经不在当前发布列表中，后台仍保留当前素材 ID，不会静默清空。
- 编辑位置时提交 `set_position=1`。服务端会检查是否存在运行中的任务；运行中时拒绝改位置，避免后台保存覆盖 Worker 的权威路线。没有运行任务时才清除旧任务指针、恢复 `rest` 并广播位置变化。
- `town_npc_admin_force_action()` 使用与 Worker 相同的路线、导航版本和碰撞校验，管理员可以在 Agent 暂停时分配 `walk`、`work`、`talk` 或 `rest`。如果村民已有运行任务，服务端返回 `npc_busy`，不会并发覆盖。
- `town_npc_clear_memories()` 删除指定村民的长期记忆，并写入 `memory_cleared` 事件和 `memory` 活动日志；没有删除数量时也保留审计记录。
- `admin_web.php` 新增 `town_npc_force_task` 和 `town_npc_clear_memory` 操作；普通用户接口和 Worker 接口没有因此获得管理员能力。
- 新增 `town_npc_schedule_save` 和 `town_npc_replace_schedule()`。表格保存会过滤空行动、校验时间范围、按星期/开始时间排序，并写入 `schedule_changed` 事件。

本次本地证据：

- `php -l server/deskbox-api/api/_app/town_npcs.php`、`admin_web.php`、`admin/index.php` 均通过。
- `server/deskbox-api/tests/town_npc_task_execution_test.php` 增加管理员强制任务、清除记忆和表格日程检查，共 11 项通过。
- `town_agent_observability_test.php`、`town_npc_navigation_test.php`、`shared_town_service_test.php` 继续通过。

线上部署证据（2026-11-11）：

- 先备份到 `/root/deskbox-backups/stage3-npc-admin-20261111-105218`，再原子替换 `api/_app/town_npcs.php`、`api/_app/admin_web.php` 和 `api/admin/index.php`。
- 先备份到 `/root/deskbox-backups/stage3-schedule-20261111-110529`，三个文件通过远程 SHA-256 读回校验；服务器 PHP 8.2 对三个文件均报告无语法错误。
- 线上 `deskbox-npc-worker` 保持 `running`，重启次数为 0；本次后台文件更新没有重启或修改 Worker。

本切片仍未声称阶段 3 完成：尚未加入工作点实体、关系编辑、记忆查看、模型连接测试和成本额度；JSON 日程仍保留作高级兜底。下一步优先建立真实的农田/商店/工坊工作点，替换 `farm-zone-3` 等固定演示目标。

### 18.5 当前阶段基线与优先级（2026-11-11）

本节用于区分“本地代码已经完成”和“线上已经可测试”。030 工作点切片现已完成远程迁移、文件同步和一次真实 Worker 工作任务验收。

| 优先级 | 工作项 | 当前状态 | 证据 / 缺口 |
| --- | --- | --- | --- |
| P0 | 真实工作点基础 | 已完成并上线 | `database/030_shared_town_work_points.sql` 新增 `town_work_points`、`town_work_runs`，并提供农田、集市、工坊三条默认记录；`town_npcs.php` 会优先读取工作点坐标，完成工作后写入 `town_work_runs`。PHP 语法检查通过，`town_npc_task_execution_test.php` 13 项通过；线上三条工作点已读回，Worker 真实完成一次二号工坊任务并生成工作记录。 |
| P1 | 工作结果真正影响世界 | 农田、集市、工坊首版已完成 | 031 持久化农田浇水；033 新增集市库存和工坊产物表，工作完成事务会更新库存或写入产物，并通过世界接口返回。更复杂的种植成长、收获和玩家交易仍未完成。 |
| P1 | 交互点预约与任务锁 | 已完成首版 | 032 新增工作点预约表；任务开始时事务占用，任务完成/失败释放，过期预约可接管；同一工作点的并发任务会返回 `work_point_busy`。 |
| P1 | 道具与库存能力 | 已完成首版 | 032 新增 NPC 道具库存；`ladder`、`bridge_kit` 映射为导航能力，任务创建时按路线消耗 1 件，失败退还；无道具时服务端拒绝路线。复杂道具、玩家共用库存仍未完成。 |
| P2 | 动态导航强化 | 首版跨高度已完成，仍在扩展 | 基础 A*、阻挡、楼梯、河道、导航版本和按版本失效的进程缓存已可用；本次补齐一格高度差必须经过楼梯、路线节点携带高度、NPC 逐格移动写入运行高度和路线高度复核。桥梁建造排队、复杂交互点调度和真实编辑素材验收仍未完成。 |
| P2 | 关系、记忆和协同 | 部分完成 | 已有关系变化、双向记忆、确定性摘要压缩、管理员查看/校准，以及 Worker 受控读取最近关系和未过期长期记忆；多人连续对话协同、语义检索、冷却和关系衰减仍未完成。 |
| P2 | 模型运营 | 部分完成 | 模型配置、连接测试、调用日志、Token 记录、严格预留/结算、月额度和超额回退、费用快照和供应商修订历史已上线；线上价格配置后的长期账本归档仍未完成。 |
| P0 | 线上验收 | 工作点/跨高度切片已完成，整体验收未完成 | 030、037 迁移、线上 PHP 校验、工作点寻路、跨高度路线和 `town_work_runs` 已有文件/数据库证据；双账号 WebSocket、双 Worker 接管、长时间运行和其他工作类型仍待验收。 |

当前最合适的实施顺序是：

1. **实现农田实际结果**：工作完成后在服务端事务中更新一块作物的浇水/状态记录，并在后台或小镇界面显示结果。这样可以验证“到达工作点 → 执行工作 → 世界状态改变”的第一条可见闭环。
2. **再做交互点预约和道具库存**：先支持一个工作点一个占用者，再加入梯子、桥梁的消耗和缺少道具时等待/绕路。
3. **补齐其他工作结果**：把集市补货、工坊产物接入真实的库存或产物表，并保留 `town_work_runs` 审计记录。
4. **最后扩展关系记忆、模型成本和复杂协同**：这些依赖前面的行动、结果和日志稳定，否则很难判断是 Agent 决策问题还是基础执行问题。

因此，当前阶段不建议优先增加更多人格字段或复杂对话；030 工作点、031 农田浇水、032 预约/道具和 033 集市/工坊产物链路已上线并验证，下一步进入模型额度和社交记忆，同时补做动态导航验收。

### 18.6 030 工作点线上部署证据（2026-11-11）

- 远程数据库先备份到 `/root/deskbox-backups/stage4-work-points-20261111/database.sql`，备份包含 80 张表，文件大小约 8.8 MB。
- 执行 030 迁移后，远程 `town_work_points` 中确认 3 条启用记录：`farm-zone-3=(128,120)`、`market-zone-1=(96,96)`、`workshop-zone-2=(112,96)`；`town_work_runs` 初始为 0。
- 先备份三个线上 PHP 文件，再同步 `api/_app/town_npcs.php`、`api/_app/admin_web.php`、`api/admin/index.php`。三份线上文件 SHA-256 与本地完全一致，PHP 8.2 语法检查均无错误。
- 使用当前 Worker 租约让启用且未暂停的“周岩”执行 `workshop-zone-2`，接口返回 HTTP 200，任务状态为 `running`，目标工作点由数据库记录解析为二号工坊。
- 等待 Worker 完成后，周岩运行状态回到 `rest`，远程 `town_work_runs` 变为 1；最新记录的 `job=craft_item`、`status=completed`、结果为 `{"effect":"craft_item","resource":"workshop_output"}`。
- 当前线上“林野”已停用且暂停，“花青”处于暂停并保留旧任务；本次没有擅自修改这两个管理员状态。后续农田闭环验收需要先由管理员在后台启用并恢复林野，或者明确指定其他可执行村民。

030 部署证据先证明了“工作点配置 → 服务端寻路/任务 → Worker 完成 → 工作运行记录”的闭环；031 农田结果切片随后补上了农田状态改变；032 又补上了工作点预约和道具能力；033 把集市库存和工坊产物接入真实世界数据。

### 18.7 农田浇水世界结果（2026-11-11）

- 新增迁移 `database/031_shared_town_farm_state.sql`，建立 `town_farm_plots`，并为三号农田创建 `farm-plot-3-1` 小麦地块。
- `town_npc_record_work_result()` 在完成 `work` 任务的同一事务中调用农田结果处理；`water_crop` 会更新 `state=watered`、`watered_at` 和 `watering_count`，同一 UTC 日期重复浇水保持幂等，但仍保留每次工作审计记录。
- `GET /api/v2/town/world.php` 返回 `farm.plots`，客户端可以在现有世界快照中读取地块状态；迁移未完成的旧实例会安全返回空列表。
- 本地 `town_farm_result_test.php` 通过 6 项检查；原有 NPC 任务、导航和 Agent 观测测试继续通过。
- 远程先备份到 `/root/deskbox-backups/stage4-farm-state-20261111/database-before-031.sql`，再执行 031，确认线上存在 1 个启用地块；`town_npcs.php` 和 `world.php` 远程 SHA-256 与本地一致，PHP 8.2 语法检查通过。
- 使用线上 Worker 租约让周岩执行一次 `water_crop`，任务 HTTP 200，逐格到达 `(128,120)` 后完成；线上地块变为 `state=watered`、`watering_count=1`，最新 `town_work_runs.result_json.world_effect.applied=true`，NPC 回到 `rest`。

这一步已经证明“工作完成 → 世界状态改变 → 状态可被世界快照读取”。它仍未实现地块种植、成长、收获、玩家交互，也没有把集市和工坊结果接入库存或产物系统。

### 18.8 交互点预约与道具能力（2026-11-11）

- 新增迁移 `database/032_shared_town_npc_inventory_and_reservations.sql`，建立 `town_npc_inventory` 和 `town_work_point_reservations`。
- 服务端从 NPC 库存合并 `ladder`、`bridge_kit` 导航能力；路线经过河道或楼梯时，如果没有所需能力，动作不会创建；有道具时任务创建事务消耗 1 件，并将消耗记录写入任务 payload。
- 任务执行期间把已消耗能力视为当前任务的临时通行能力，避免道具扣除后逐格推进被自身规则阻断；导航版本变化或路线失败会退还道具。
- 工作任务开始时占用工作点，TTL 至少 120 秒并随任务时长延长；完成、失败或过期释放占用。管理员页面现在可以为每个村民设置梯子和桥梁工具数量。
- 本地 `town_npc_inventory_reservation_test.php` 通过 8 项检查，覆盖无桥梁工具拒绝、持有工具跨水、道具消耗、完成后释放预约，以及预约过期后的任务终止和道具退还；原有 NPC、农田、导航和 Agent 测试继续通过。
- 远程先备份到 `/root/deskbox-backups/stage4-inventory-20261111/database-before-032.sql`，执行 032 后确认两张表存在；三个 PHP 文件远程 SHA-256 与本地一致，PHP 8.2 语法检查通过；线上 Worker `deskbox-npc-worker:v11` 仍为 `running`，重启次数为 0。
- 随后的预约过期修正只更新 `town_npcs.php`，已再次备份、上传并完成 SHA-256 读回和 PHP 8.2 语法检查；没有重启 Worker。

当前限制是：线上还没有给样例村民配置梯子或桥梁工具，也没有进行真实河道穿越验收；下一步需要在管理后台给一个测试村民配置 1 件 `bridge_kit`，再进行一次短路线线上验收。若要改变“每次穿越消耗 1 件”的规则，需要在继续前由用户指定新的消耗策略。

### 18.9 集市库存与工坊产物（2026-11-11）

- 新增迁移 `database/033_shared_town_market_workshop_state.sql`，建立 `town_market_stocks` 和 `town_workshop_outputs`；一号集市默认库存键为 `market-stock-1`，默认商品 `market_goods`，初始数量 0。
- 集市 `restock_market` 完成后会更新库存数量，默认每次增加 1；工坊 `craft_item` 完成后会新增产物记录，默认产物为 `crafted_item`、数量 1。管理员可以通过工作点结果 JSON 配置 `quantity`、`stock_key` 和 `item_id`。
- 世界快照 `economy` 字段返回当前集市库存和最近 100 条工坊产物；NPC 工作运行记录继续保存具体 `world_effect` 结果。
- 本地 `town_npc_economy_result_test.php` 通过 6 项检查，覆盖集市库存增加、工坊产物落表和世界快照读取；农田、预约、任务等原有测试继续通过。
- 远程先备份到 `/root/deskbox-backups/stage4-economy-20261111/database-before-033.sql`，执行 033 后确认集市默认库存和工坊产物表存在；两份 PHP 文件 SHA-256 读回一致，PHP 8.2 语法检查通过。
- 线上首次真实任务暴露了预约外键顺序问题：预约曾在任务行创建之前插入，导致返回 `work_point_busy`。已修正为同一事务中先插入任务再占用工作点，并再次备份、上传和校验。
- 修复后“周岩”执行二号工坊任务返回 HTTP 200；Worker 完成后，线上产生 1 条 `crafted_item` 产物，数量 1；预约状态变为 `completed`，周岩回到 `rest`。线上集市补货仍待真实任务验收。

当前只实现世界结果数据；尚未实现玩家购买、产物领取、自动物流和完整经济循环。这些不作为首版 NPC 行动闭环的阻塞项，但不能宣称已完成。

### 18.10 模型连接测试与月度 Token 额度（2026-11-11）

本阶段先补齐模型运营的低耦合部分，避免在继续增加对话和协同前无法判断模型是否可用、额度是否已经耗尽：

- 管理后台 \`town_agent\` 页面新增“测试模型连接”按钮。它只读取已经保存的 provider，通过服务端访问 OpenAI 兼容的 \`/models\` 端点，不把 API Key 交给浏览器，也不会发送 \`chat/completions\` 请求或创建 Agent 运行记录。
- 连接测试同时覆盖配置未保存、配置停用、TLS/网络错误和非 2xx 响应；页面只显示可供管理员判断的结果，不回显响应正文或密钥。
- \`town_model_provider_budget()\` 按 UTC 自然月统计当前 provider 在 \`town_agent_runs.total_tokens\` 中已经产生的 Token，\`monthly_quota=0\` 继续表示不限额。后台报表显示本月已用、额度和剩余 Token。
- Worker 的模型配置响应现在带有本月已用和剩余 Token。达到额度后，Worker 不再发起模型请求，而是记录 \`monthly_quota_exceeded\` 并使用确定性日程回退；没有模型配置时的原有回退行为不变。
- 新增 \`tests/town_model_provider_test.php\`，覆盖 provider 连接测试回调、密钥不可用时拒绝、月度 Token 汇总和停用 provider 拒绝。当前本机 PHP 没有 sodium 扩展，因此测试在该环境跳过加密成功分支；生产 PHP 8.2 仍要求 sodium 扩展来解密后台保存的 API Key。
- PHP 语法检查、模型运营测试、Agent 观测测试、NPC 任务/导航/农田/库存/经济测试以及 Worker Debug 构建均通过。

本切片仍未完成费用金额计算、输入/输出单价、供应商切换历史和严格的并发 Token 预留；当前月额度以已记录的总 Token 为准，单次调用在额度边界附近可能在返回后才反映完整使用量。下一步需要先完成线上桥梁/梯子、工作点并发、双 Worker 租约接管和双账号 WebSocket 验收，再决定是否引入费用金额和预留账本。

线上部署证据（2026-11-11）：

- 远程 PHP 文件部署前备份到 \`/root/deskbox-backups/model-ops-20261111-453063\`；\`town_model_provider.php\`、\`town_agent.php\`、\`admin_web.php\`、\`admin/index.php\` 和 \`agent/model.php\` 上传后逐文件 SHA-256 读回一致。
- 使用服务器 PHP 8.2 CLI 对上述五个文件检查，均无语法错误；本次没有新增数据库迁移。
- Worker 镜像更新为 \`deskbox-npc-worker:v12\`，旧 \`v11\` 保留为 \`deskbox-npc-worker-v11-model-ops-20261111\` 回滚副本。新容器健康接口返回 200，状态为 running，重启次数为 0。
- 线上服务令牌鉴权调用 \`agent/model.php\` 返回 200，响应包含 quota 汇总字段；没有在验证输出中打印 provider 密钥。
- 使用同一镜像临时启动第二个 Worker（不同 \`NPC_WORKER_ID\`，不暴露服务端口）进行租约探测；第二实例收到 HTTP 409 后持续只读重试，未产生动作写入，探测容器重启次数为 0，随后已删除。该结果证明当前全局租约拒绝并发 Worker，但还不是“主 Worker 失联后的接管”验收。

### 18.11 线上桥梁道具短路线验收（2026-11-11）

- 按用户确认，临时给线上“周岩”配置 1 件 \`bridge_kit\`；为避免干扰日常位置，测试前把他临时放到 \`(64,1)\)，测试结束恢复到原来的 \`(112,96)\`，Agent 暂停状态恢复为原值，库存恢复为空。
- 服务端创建从 \`(64,1)\` 到河道格 \`(64,0)\` 的两格路线，任务完成状态为 \`completed\`。任务持久化 payload 明确记录 \`required_capabilities=[\"bridge_kit\"]\` 和 \`consumed_items={\"bridge_kit\":1}\`，证明河道不是被当作普通地面穿越。
- 线上任务完成后查询确认：\`town_npc_inventory\` 中桥梁工具数量为 0，NPC 无运行任务、位置为 \`(112,96)\`、未暂停；Worker \`v12\` 健康接口返回 200，重启次数为 0。
- 该验收覆盖“有道具可过河并消耗一次”的路径；无道具拒绝和失败退还已有本地 032 测试覆盖，线上还需要补一次不改变样例状态的短路线拒绝/退还验证。
- 随后在线上清空周岩的 \`bridge_kit\`，请求同一河道目标；服务端返回 \`no_route\`，没有创建任务，周岩仍为 \`(112,96)\)、无当前任务、库存为 0。由于寻路阶段直接找不到可行路线，本次拒绝码是 \`no_route\` 而不是动作阶段的 \`missing_capability\`，两者都表示没有道具不能穿越。

### 18.12 Worker 租约接管线上演练（2026-11-11）

- 短暂停止主容器 \`deskbox-npc-worker:v12\`，将 \`main\` 租约设置为过期，然后用不同 Worker ID 请求接管；第二 Worker 收到 HTTP 200，返回自己的租约持有者和 30 秒 TTL。
- 随后再次让测试租约过期并启动主容器；主容器恢复为 running、健康接口返回 200、重启次数为 0，数据库最终持有者恢复为 \`town-worker-1\`，说明接管演练没有留下第二实例。
- 本次只改变租约行和容器生命周期，没有修改 NPC、地图、库存或任务数据。该证据覆盖“租约过期后可接管”和“主 Worker 恢复”两条路径；多 Worker 长时间压力测试仍未完成。

### 18.13 NPC 社交结果闭环（2026-11-11）

本轮补上了首个可持久化的 NPC-to-NPC 对话结果，并把交谈目标从“仅校验 ID”补齐为“按对方实时位置寻路”：

- `talk` 任务创建时校验目标 NPC 存在、启用且不是自己；如果没有脚本明确提供坐标，服务端从目标 NPC 的运行状态读取当前 `(tile_x,tile_y)`，生成服务器校验的逐格路线。模型不能把坐标直接写入运行状态。
- Worker 逐格推进到目标位置后才进入 `talk` 状态；达到任务时长才完成。对话时长为 0 时也至少经过到达目标的推进步骤，避免把远处 NPC 误判为已经交谈。
- 完成对话后，服务端在同一事务中给双方关系各增加 1 点好感（范围仍限制在 -100～100），在双方各写入一条 `conversation` 长期记忆，并写入一条 `conversation` 事件和一条 `memory` 活动日志。对话文本、任务 ID 和目标 NPC ID 会进入审计数据。
- 关系/记忆表尚未迁移的旧实例仍会安全跳过社交结果写入；已迁移实例使用完整关系和记忆表，不影响已有工作、导航和库存事务。

本地证据：

- `server/deskbox-api/tests/town_npc_social_test.php` 通过，覆盖目标坐标解析、逐格到达、对话完成、双向好感、双方记忆和 conversation 事件。
- `town_npc_task_execution_test.php` 13 项、`town_npc_navigation_test.php` 9 项、`town_npc_inventory_reservation_test.php` 8 项、`town_npc_economy_result_test.php` 6 项、`town_farm_result_test.php` 6 项、`town_agent_observability_test.php`、`town_model_provider_test.php` 均通过；`town_npcs.php` 本地语法检查通过。

线上部署证据（2026-11-11）：

- `api/_app/town_npcs.php` 已先备份到 `/root/deskbox-backups/social-target-route-20261111-151846` 后上传。
- 服务器 PHP 8.2 语法检查无错误，远程 SHA-256 为 `f1f583a43649969f7384bb3577dd7889c353424fae9ccb5a8962903a44ab337e`，与本地文件一致。
- 本次没有启用或移动线上其他村民，也没有改变 Worker、地图、库存或租约；线上真实双 NPC 对话验收仍待两个启用村民可用后执行。

当前社交限制：

1. 线上主要只有周岩处于启用状态，林野/花青存在停用或暂停状态；没有擅自改变这些管理员状态，因此暂不宣称线上真实对话已验收。
2. 关系查看/编辑、长期记忆分页与摘要、多人连续对话和模型驱动的情绪变化尚未完成。
3. 对话文本目前由任务 payload/模型意图提供，服务端只负责校验、执行和持久化；尚未加入对话内容审核、重复对话冷却和关系衰减。

### 18.14 关系与长期记忆管理面板（2026-11-11）

- `town_npc_relationship_rows()` 和 `town_npc_memory_rows()` 提供管理员只读查询，分别返回双向关系、好感度、最近互动元数据，以及记忆类型、内容、重要度、创建/过期时间；查询只读，不暴露模型密钥或完整提示词。
- 村民管理页新增“关系与长期记忆”区块。关系按方向显示，所以一次 NPC 对话会显示双方各自的好感记录；最近 200 条记忆按时间倒序显示。没有执行 028 迁移或旧实例缺表时，区块安全显示“暂无记录”。
- 现有“清除长期记忆”按钮继续按单个 NPC 执行，并写 `memory_cleared` 事件和活动日志；查看面板没有新增写权限。

本地证据：

- `town_npc_social_test.php` 增加关系查询和记忆查询断言，全部通过；`town_npcs.php`、`api/admin/index.php` PHP 语法检查通过。

线上部署证据（2026-11-11）：

- 先备份到 `/root/deskbox-backups/social-admin-20261111-152817`，再同步 `api/_app/town_npcs.php` 和 `api/admin/index.php`。
- 服务器 PHP 8.2 语法检查无错误；远程 SHA-256 与本地一致：`town_npcs.php` 为 `3393093e524507ddc9ad25573c3a8002dbac6ed8a877a2b7ca9452c595729dee`，`admin/index.php` 为 `d48cfad4fc733bf9e2f6dd5dbad5934f2dbef9fcf27330b38a4b525e8c73611e`。
- Worker `v12` 仍为 running，健康检查返回 200，未因后台页面更新重启。

该切片完成“关系和记忆可被管理员查看”的基础闭环；筛选分页、摘要压缩、关系手工编辑、记忆语义检索和线上真实双 NPC 对话仍未完成。

### 18.15 管理员关系校准（2026-11-11）

- 关系表每行增加管理员保存控件，输入范围为 -100～100；保存时服务端同时更新双方方向，避免后台只改一侧造成关系不一致。
- `town_npc_save_relationship()` 只允许两个已启用且不同的 NPC，写入 `admin_override` 元数据，并记录 `relationship_changed` 事件和活动日志；普通用户和 Worker 接口没有获得该写权限。
- 本地社交测试新增“双向关系保存”断言并通过；`town_npcs.php`、`admin_web.php`、`admin/index.php` 语法检查通过。
- 线上已备份到 `/root/deskbox-backups/social-admin-edit-20261111-153412` 后同步三个文件；服务器 PHP 8.2 语法检查通过，远程文件与本地哈希一致，Worker `v12` 保持运行。
- 共享小镇 PHP 回归全部通过：`shared_town_service_test` 32、`town_asset_footprints_test` 47、`town_farm_result_test` 6、`town_model_provider_test`、`town_npc_economy_result_test` 6、`town_npc_inventory_reservation_test` 8、`town_npc_navigation_test` 9、`town_npc_social_test`、`town_npc_task_execution_test` 13、`town_player_positions_test` 53；依赖未配置生产 MySQL 的两个测试按预期跳过。

关系可以被管理员校准，但尚未实现关系衰减、关系规则模板、批量编辑和线上双 NPC 对话验收。

### 18.16 线上双 NPC 对话验收（2026-11-11）

- 按用户授权临时启用并恢复“林野”，先停止主 Worker 以避免自动规划抢占测试任务；直接通过服务器服务层创建“林野 → 周岩”的 `talk` 任务，未向浏览器或数据库写入任何绕过服务端校验的坐标。
- 任务从林野当前 `(128,120)` 规划到周岩 `(112,96)`，服务器返回 41 个格子的路线；恢复 `deskbox-npc-worker:v12` 后由 Worker 逐格推进并完成，林野最终在周岩位置 `(112,96)`，任务指针清空、状态回到 `rest`。
- 线上数据库确认双方各有一条关系记录，`affinity=1`，`last_interaction=talk`，并各有一条 `conversation` 记忆；对话事件和活动日志同时存在。记忆内容已修正为以各自 NPC 为主语的对方视角。
- 验收没有改变周岩的位置、暂停状态、库存或任务；花青仍保持原来的暂停状态。Worker 恢复后健康接口 200、容器运行正常。
- 记忆视角修正后的 `town_npcs.php` 已再次备份到 `/root/deskbox-backups/social-perspective-20261111-160150`，服务器 PHP 8.2 语法检查通过，远程 SHA-256 为 `7b17b800364c45299d9d2a74c2c38610c80f59505caf61b4965b70f871338668`。
- 随后修正关系筛选查询的 MySQL 原生预处理参数名（同一值使用两个占位符），并再次部署；最新备份为 `/root/deskbox-backups/social-filter-fix-20261111-160624`，PHP 8.2 语法通过，远程 SHA-256 为 `4d3d07a01e5cf09c2dde1cb8915ef477d4a973608b1e4f92fa3d45eb5e08a395`。

这完成了“目标 NPC 实时位置 → 服务端路线 → Worker 逐格移动 → 双向关系/记忆/事件落库”的线上闭环。多人连续对话、模型人格差异和对话冷却仍需后续实现。

### 18.17 当前权威进度表（2026-11-11）

以下表格覆盖前文早期“待实施”描述；继续开发时以本节及其后的更新为准。

| 模块 | 当前状态 | 已完成的可验证内容 | 仍缺少的内容 |
| --- | --- | --- | --- |
| 数据库与样例 NPC | 已上线 | 028～034 迁移、5 个样例村民、人格/家庭/目标/职业/7 天日程、运行状态、事件、日志、库存、工作点、农田/集市/工坊结果；新增沈禾、顾宁各 21 条日程并已由线上 Worker 运行 | 玩家经济闭环未做；5 人客户端同屏仍需真实登录画面验收 |
| 导航与通行 | 首版上线 | 2D 网格 A*、plain/blocked/stairs/water/bridge、楼梯连接方向、导航版本、梯子/桥梁能力、道具消耗和失败退还、服务端逐格校验、地图变更后的主动重规划、按导航版本失效的有界进程缓存 | 跨高度状态、桥梁建造和排队 |
| Worker | 上线运行 | Docker `v21`、全局 30 秒租约/续租/接管、租约状态并发保护、同一持有者重复获取不轮换 Token、失效租约刷新重试、确定性回退、模型超时隔离、有限退避、逐格 advance、健康检查和实时连接指标；模型计划进入服务端前增加行动/steps/通行能力白名单校验；已完成 v21→v20→v21 真实回滚演练、约 5 分钟双 Worker 租约竞争压力和服务端 steps 前置条件校验 | — |
| 模型运营 | 部分完成 | Web 配置、服务端加密、连接测试、OpenAI 兼容调用、输入/输出/总 Token、月 Token 额度和超额回退、按村民日志报表、调用前原子 Token 预留和完成后按实际用量结算；036 已上线费用单价/币种、供应商修订历史和运行成本快照表；后台已增加费用明细和修订历史只读表 | 线上尚未配置价格并产生可计价记录；费用金额报表和供应商历史仍需真实数据后的长期归档验收 |
| 实时同步 | 已上线代码 | WebSocket 玩家/NPC 事件、快照/增量、断线回退、旧响应过滤、NPC 逐格状态广播 | 两个真实账号的线上视觉验收、长连接压力/断线演练 |
| NPC 工作 | 首版闭环 | 农田浇水、集市补货、工坊产物、工作点预约和审计记录 | 种植成长/收获、玩家购买/领取、完整物流经济 |
| NPC 社交 | 首版闭环 | 交谈目标按对方实时位置寻路、逐格到达、双向好感、双方 conversation 记忆、确定性摘要压缩、相关性检索、输入安全过滤、事件/活动日志、后台查看和双向校准、服务端 60 秒重复交谈冷却、线上真实验收 | 多人连续协同、人格差异对话策略、语义向量检索、关系衰减、敏感内容审核 |
| Web 管理 | 部分完成 | 村民 CRUD/编辑、位置、暂停/恢复、强制任务、道具、日程、关系/记忆查看和清除、关系校准、关系/记忆分页、批量暂停/恢复 Agent、批量启用/停用、模型配置和报表；Agent 页面增加逐次费用和供应商修订历史查看 | 关系筛选、带真实历史数据的长期报表验收 |
| 第一版验收 | 进行中 | 线上桥梁道具、Worker 租约接管、工作点/农田/工坊、NPC 双人对话、地图修改后重规划、5 人数据库快照、新增村民日程、无模型配置确定性回退、严格 Token 预留、v19 长时间单 Worker 观察、v21→v20→v21 回滚演练、双 Worker 租约竞争压力、服务端 steps 前置条件校验和无模型人格化对话回退已完成 | 5 人同屏、双账号 WebSocket 画面（按用户要求留到最后）、断线重连人工验收、模型驱动的不同人格线上对话、成本数据长期归档 |

根据用户安排，双账号 WebSocket 画面验收留到最后。下一阶段先补模型供应商运营、社交记忆摘要/冷却和完整后台管理，再回到 5 人同屏、断线重连及双账号人工验收；不要在这些验收完成前继续扩大 NPC 行为类型。

### 18.18 线上地图变更主动重规划验收（2026-11-11）

本次验收验证了“NPC 已经走到一半时修改地图，服务端主动从当前位置重新规划，Worker 继续逐格执行并完成任务”的真实线上闭环。验收期间没有取消线上原有任务，也没有改变管理员原有配置；测试使用林野作为临时目标，周岩保持空闲，花青原有任务和暂停状态保持不变。

- 线上先确认 Worker `deskbox-npc-worker` 已恢复运行，调用链持续返回 HTTP 200；验收前林野位于 `(128,120)` 且无当前任务。
- 临时创建一项 `walk` 任务，目标为 `(132,120)`。任务初始路线沿 `y=120` 前进，Worker 推进一步后位置为 `(129,120)`，任务仍为 `running`。
- 在 `(130,120)` 临时加入 `blocked_npc=true` 的素材对象并执行导航重建，`navigation_version` 从 1 增加到 2。服务端没有让 NPC 穿过阻挡，也没有瞬移或丢弃任务。
- 服务端写入 `route_replanned` 事件：从林野真实当前位置 `(129,120)` 重新规划为 `(129,120) → (129,121) → (130,121) → (131,121) → (132,121) → (132,120)`，并记录旧/新导航版本、旧路线指针和所需能力。
- Worker 随后逐格推进，任务最终为 `completed`，林野到达 `(132,120)`，`replan_count=1`；事件日志和任务 payload 同时保留重规划证据。
- 验收结束后删除临时素材和对象，再次重建导航（版本 3），恢复三个样例村民的原位置、任务和 `agent_paused` 标志。远程复核确认临时素材数量为 0：周岩 `(112,96)` 未暂停，林野 `(128,120)` 未暂停，花青 `(96,96)` 保持原任务并暂停。
- 本次验证的是“有替代路线时主动重规划”；无替代路线时的失败、工作点释放和临时道具退还已有本地测试覆盖。线上长时间压力、多 Worker 连续接管和双账号 WebSocket 画面仍未完成。

这一切片将导航模块和第一版线上验收表中的“地图修改后重规划”从待办移入已完成证据。下一优先级是 5 人同屏与双账号 WebSocket 的人工画面验收，再补无密钥/回滚演练和多 Worker 长时间运行验证。

### 18.19 五人样例村民与线上 Worker 运行验收（2026-11-11）

为达到第一版“5～10 个村民”的最低样例规模，新增可重复执行的 `database/034_shared_town_npc_samples_v1.sql`。迁移只使用固定 ID 的 `INSERT IGNORE`：已有三名村民的姓名、位置、任务、库存、暂停标志和日程不会被覆盖；新村民没有现有记录时才写入资料、运行状态和 7 天日程。

- 线上执行迁移前先保存 `/root/deskbox-backups/npc-samples-v1-20261111-090445/before.json`，执行后复核新增两人各有 21 条日程；重复执行再次得到 `npc_count=2`、`schedule_count=42`，证明不会重复插入或覆盖。
- 新增样例为沈禾（农夫，固定起点 `(136,120)`）和顾宁（商人，固定起点 `(104,96)`），均启用且未暂停；两人分别配置了独立人格、家庭背景、短期/长期目标、说话方式、价值观和恐惧字段。
- 线上复核当前 5 人均存在：林野、花青、周岩、沈禾、顾宁；原三人状态保持为林野 `(128,120)` 未暂停、花青 `(96,96)` 保留原任务并暂停、周岩 `(112,96)` 未暂停。新增两人均为 `rest`、无当前任务。
- Worker `deskbox-npc-worker` 在迁移前后持续 `running`，`restart_count=0`，健康接口返回 HTTP 200。运行窗口内顾宁至少完成多次 `work` 任务，任务 payload 含模型生成的 `mood`、`steps`、`dialogue`、目标和服务端路线；沈禾也已产生农田工作任务和心情/对话日志。
- 这一步证明了“5 人资料 → Worker 快照 → 模型/确定性调度 → 服务端任务 → 事件和活动日志”的线上数据闭环。由于当前公网验收尚未使用两个真实登录客户端，仍不能把数据库 5 人数量当作 5 人同屏视觉或双账号 WebSocket 验收。

下一步按优先级进行真实登录画面验收：先在两个客户端同时打开共享小镇，确认 5 个 NPC 的姓名、位置、逐格移动和对话气泡一致，再进行断线重连及长连接压力观察。线上活动素材发布版本由管理员当前编辑内容决定，本迁移没有覆盖素材发布配置。

### 18.20 NPC 图集恢复、计划校验与 Worker v13（2026-11-11）

本阶段处理了客户端人物纹理缺失和模型返回计划过于宽松两个问题，并将改动部署到线上。素材操作按用户授权执行：保留当前 v9 的两项工具素材，把历史 v8 中的三个 NPC 图集合并到新的活动版本；没有回滚地图对象，也没有覆盖其他素材。

- 素材发布前先备份当前活动配置；第一次合并产生的 v10 因配置字段识别错误导致 `sheets` 为空，未作为最终版本使用。随后立即用 v10 当前五个素材项和 v8 的 `sheet_id` 字段生成修正版 v11，v10 仍保留在发布历史中可回滚。
- 当前活动版本为 v11，配置包含 5 个素材项和 4 张图集：`town-source-yidongche`、`town-source-che`、`town-source-npc-shane`、`town-source-npc-caroline`、`town-source-npc-alex`。四张图集文件均存在，服务器按配置 SHA-256 读回一致；备份目录为 `/root/deskbox-backups/npc-assets-merge-20261111-094354` 和 `/root/deskbox-backups/npc-assets-merge-fix-20261111-094545`。
- 为避免后台下一次“发布当前草稿”再次丢失人物素材，三个 NPC 项目已恢复到数据库草稿；备份为 `/root/deskbox-backups/npc-assets-drafts-20261111-095214`。恢复脚本逐项比较旧草稿，确认原有草稿内容未改变。后续后台发布会从 5 项草稿生成完整配置。
- `src/DeskBox.NpcWorker/Program.cs` 新增 `AgentPlanValidator`：行动只允许 `walk/work/rest/talk`，`steps` 最多 16 步，目标和对话长度有限，通行能力只允许 `ladder/bridge_kit`；坐标、碰撞、道具实际拥有量和最终执行仍由 PHP 服务端校验。非法 JSON、未知行动或未知能力只会进入模型失败回退，不会创建任务。
- Worker 测试 `DeskBox.NpcWorker.Tests` 通过 11/11；DeskBox Debug 构建无错误，canonical 进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。
- 线上构建备份为 `/root/deskbox-backups/worker-v13-20261111-100544`，镜像 `deskbox-npc-worker:v13` 已运行，健康接口 HTTP 200，重启次数 0；线上 NPC 快照 HTTP 200，返回 5 人，导航版本为 3；模型运营接口 HTTP 200，月额度为不限额，当前已记录 Token 约 1,919,645。
- 线上租约表仍由 `town-worker-1` 持有并持续续租。当前数据库中的 Agent 暂停标志由管理员状态决定，本次部署没有修改它们；部署验收只读确认了五人位置、任务和租约，没有替用户恢复或暂停任何村民。

本阶段完成了“配置人物图集 → 活动素材版本 → 客户端可取得图集”和“模型计划 → Worker 白名单校验 → 服务端行动校验”的两条基础链路。仍未完成双账号真实画面验收、断线重连压力、无模型密钥回退和多 Worker 长时间接管；这些仍是第一版结束前的验收项。

### 18.21 严格 Token 预留与 Worker v18 租约竞态修复（2026-11-11）

本次把模型额度从“模型调用完成后统计”推进为“调用前先占用、完成后按实际 Token 结算”的并发安全链路，并修复了 Worker 在续租/重新获取租约时偶发把旧租约带入额度请求的问题。

- 新增 `database/035_town_agent_token_reservations.sql`，建立 `town_agent_token_reservations`。每条预留记录 provider、worker、预留 Token、实际 Token、状态、过期时间和结算时间；状态包括 `reserved`、`settled`、`released`、`recorded`。
- `town_model_provider_budget()` 统计当前 UTC 月的已结算 Token 与未过期预留 Token。预留前在事务中锁定 provider 配置并清理已过期预留，再检查 `used + reserved + estimated <= monthly_quota`；不限额 provider 仍使用 `monthly_quota=0`。
- 新增服务令牌接口 `api/v2/town/agent/quota.php`：`operation=reserve` 原子创建预留，`operation=settle` 幂等结算或释放。接口要求有效服务令牌、Worker ID 和当前全局租约；为兼容反向代理偶发丢失租约 Header，也接受请求体中的 `lease_token`，但不会降低租约校验。
- Worker 的 `OpenAiCompatiblePlanner` 在发送 `chat/completions` 前预留默认 8192 Token（可由 `MODEL_RESERVATION_TOKENS` 调整，范围 512～32768），模型响应完成或失败后按实际总 Token 结算；没有实际用量时释放预留。额度耗尽记录 `monthly_quota_exceeded` 并走确定性日程，不会发送模型请求。
- Worker v18 的 `TownApiClient` 用同步保护读取/替换租约，串行化 `AcquireLeaseAsync`/`RenewLeaseAsync`，失败续租不会清除已经由其他操作取得的新租约。额度请求收到 `lease_required` 时强制重新获取租约后只重试一次，避免“续租 HTTP 200 但额度请求仍携带旧 Token”的竞态。
- 预留 ID 会写入 `town_agent_runs` 和活动日志；管理员报表继续按实际 `total_tokens` 统计，不把预留上限误算成消耗。过期预留会在下一次预留时自动释放，线上清理脚本只处理确认已过期的记录。

本地验证证据：

- `php server/deskbox-api/tests/town_model_provider_test.php` 通过，覆盖额度汇总、预留、结算、释放、过期预留清理和租约要求；PHP 8.2 语法检查通过。
- `dotnet test tests/DeskBox.NpcWorker.Tests/DeskBox.NpcWorker.Tests.csproj --no-restore --verbosity:minimal` 通过 12/12；`dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore --verbosity:minimal` 0 警告、0 错误。

线上部署和运行证据：

- 远程迁移 035 已执行，确认 `town_agent_token_reservations` 存在。PHP 文件部署前保留了 `/root/deskbox-backups/quota-reservation-20261111-110844/`、`quota-reservation-fix-113854/` 和 `quota-lease-fix-114915/` 备份；Worker v17 切换前保留 `/root/deskbox-backups/worker-v17-quota-120251/`。
- v18 源码包在服务器重新构建为 `deskbox-npc-worker:v18`，切换备份目录为 `/root/deskbox-backups/worker-v18-lease-122028`；旧 v17 容器保留为 `deskbox-npc-worker-v17-lease-rollback`。新容器 `running`、`restart_count=0`，`/health` 返回 HTTP 200。
- v18 切换后连续观察窗口中 `lease_required` 日志计数为 0；远程预留表当前为 `settled=26`、`released=4`、过期未释放 `reserved=0`，最新示例由 8192 预留按 1126、1093、1165 等实际 Token 结算，证明额度请求不再只依赖手工探针。
- 服务端仍保留“只在确认过期后释放”的保护，不强制删除正在执行的预留；本轮最终复核没有未过期或过期残留预留。当前 provider `monthly_quota=0`，因此本轮没有额度阻断。

本切片仍未完成费用金额和输入/输出单价、供应商切换历史、长期压力下的额度报表归档；这些属于模型运营增强项，不阻塞当前 NPC 行动闭环。下一步应进行双账号 WebSocket 真实画面验收、无模型密钥回退和长时间多 Worker 观察。

### 18.22 租约 Token 轮换竞态修复与 v19 线上验证（2026-11-11）

18.21 之后的线上日志暴露了第二层竞态：多个 NPC 规划同时进行时，一个线程在额度请求失败后调用 `acquire`，服务端原实现会无条件生成新 Token；其他线程已经创建的 `runs.php` 请求仍带旧 Token，于是运行记录连续收到 409。额度本身可能成功，但 Agent 日志、行动和完成状态会缺失，这会让“村民是否真的行动”无法从后台报表确认。

本次修复包含两部分：

- `town_agent_lease()` 在同一 Worker 仍持有未过期租约时复用原 `lease_token`，只有租约不存在、已过期或由其他 Worker 接管时才生成新 Token。这样并发恢复请求不会互相使对方的 HTTP 请求失效；其他 Worker 仍会收到 `lease_busy`。
- `TownApiClient` 的额度重试改为先续租、续租失败才重新获取；`runs.php`、NPC action 和逐格 advance 共用一次性租约错误重试。只有响应明确包含 `lease_required` 才刷新租约，业务层的 `npc_busy`、`no_route` 和 `work_point_busy` 不会被错误吞掉。

本地证据：

- `town_npc_navigation_test.php` 新增同一 Worker 重复获取租约的断言，确认 Token 保持不变；测试由 9 项增加到 10 项并通过。
- Worker 构建 0 警告、0 错误，`DeskBox.NpcWorker.Tests` 12/12 通过；模型额度测试和 PHP 语法检查继续通过。

线上证据：

- `api/_app/town_agent.php` 已部署并通过 PHP 8.2 语法检查，远程 SHA-256 为 `8fc1f7db5ffcea9f8a62168fd3c7e507a7caddb845fd18a92d023506b261fc28`；原文件备份在 `/root/deskbox-backups/lease-token-stability-20261111/`。
- Worker 镜像 `deskbox-npc-worker:v19` 已部署，v18 保留为 `deskbox-npc-worker-v18-lease-rollback`；备份目录为 `/root/deskbox-backups/worker-v19-lease-stability-20261111`。容器 `running`、`restart_count=0`，健康接口 HTTP 200。
- v19 运行后 `runs.php` 连续返回 HTTP 200；数据库 `town_agent_runs.latest=12:51:41`，Token 预留状态出现 `recorded=4`，证明预留、运行日志和结算能够关联。v19 观察窗口没有新的 `lease_required` 日志；此前 12:03～12:43 的 409 属于旧版本竞态，不能计入 v19 结果。

这一步完成了“并发模型规划不互相使租约失效”和“运行审计可持续写入”的服务端/Worker 修复。仍需通过两个真实登录客户端验收玩家与 NPC 的 WebSocket 画面同步，并进行无模型密钥回退、长时间多 Worker 和完整回滚演练。

### 18.23 v19 长时间单 Worker 观察（2026-11-11）

为确认 18.22 修复不是只在一次模型调用中生效，对线上 v19 做了约 4 分钟连续观察，每 30 秒检查一次健康接口、容器状态、最近运行记录 HTTP 状态和租约错误。

- 六次采样中健康接口均返回 `{"ok":true,"service":"npc-worker"}`；容器一直为 `running`，`restart_count=0`，镜像为 `deskbox-npc-worker:v19`。
- 每次采样的最近 20 条 `runs.php` 请求均为 HTTP 200，HTTP 409 为 0；Worker 最近 45 秒日志中的 `lease_required` 为 0。
- 观察期间运行记录持续写入，访问日志显示 13:00:05、13:01:18、13:02:30 等周期各有 started/completed 两条 200 请求，说明模型规划、运行审计和完成更新持续闭环。

这证明单 Worker 的租约稳定性和运行审计在短时连续运行窗口内通过。它不等同于多 Worker 高并发压力测试，也不替代两个真实客户端的 WebSocket 画面验收。

### 18.24 线上无模型配置确定性回退验收（2026-11-11）

按授权对线上模型配置做了一次可恢复的回退验收：先把 `town_model_providers` 全量行（包含服务端加密密文）保存到 `/root/deskbox-backups/no-model-fallback-20261111/provider-rows.json`，只把 `enabled` 临时改为 0；没有读取、输出或改写 API Key 明文。

- 模型停用期间 provider 查询显示 `enabled=0`；最近两分钟没有新的 Token 预留。
- Worker 连续两个调度窗口写入 `status=fallback,error_code=model_not_configured`，证明没有模型配置时会走确定性日程，不会阻塞 Worker。
- 验收结束后使用备份逐字段恢复 provider，复核 `enabled=1`；恢复后的窗口重新出现 Token `reserved/recorded`，说明模型调用链恢复。
- 验收前后五名村民的启用、暂停、位置和运行任务均保持原状态：顾宁仍为唯一未暂停样例，林野、花青、周岩、沈禾保持暂停；没有为本次回退擅自恢复其他村民。
- Worker v19 仍为 `running`、`restart_count=0`，健康接口 HTTP 200。

这项验收完成了“无模型密钥/配置不可用时自动回退”的线上证据；它不覆盖模型供应商网络超时、额度耗尽和密钥解密失败的每一种故障，但这些路径已有 Worker 回退代码和本地测试覆盖。

### 18.25 客户端 NPC 实时增量顺序校验（2026-11-11）

为降低双账号画面验收时的歧义，把客户端 NPC WebSocket 增量合并逻辑从 `town.js` 内联代码提取到 `town-realtime.js` 的 `mergeNpcDelta()`，与玩家 presence 使用相同的单调序列规则：旧事件和重复事件直接忽略，未知 NPC 可以由实时事件创建，坐标、路线和状态仍完全来自服务端。

- 新增脚本断言：NPC 新事件可以创建/更新，旧 sequence 不能把位置和状态回退，重复 sequence 不会重复渲染。
- `scripts/verify-town-realtime.mjs` 通过，覆盖玩家和 NPC 增量、像素位置、过期和顺序检查。
- 修改应用代码后已停止仓库内 DeskBox 进程，重新构建 `src/DeskBox/DeskBox.csproj`：0 错误、9 个既有编译警告；已启动唯一的 canonical Debug 实例 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。
- x64 客户端实时相关测试继续通过 13/13。

这一步是双账号人工验收前的客户端防回退保障；它不能替代真实登录画面、断线重连和五人同屏观察。

### 18.26 Agent 关系与长期记忆受控检索（2026-11-11）

为让模型真正使用已沉淀的社交信息，新增受控的 Agent 上下文读取：

- `town_npc_agent_context()` 从关系表读取每个村民最多 8 条定向关系，从记忆表读取最多 12 条重要且未过期记忆；内容长度限制为 1000 字符，过期记忆不会进入提示词。
- 普通用户的 `GET /api/v2/town/npcs/snapshot.php` 仍只返回渲染所需资料，不包含 `agent_context`；只有带服务令牌的 Worker 快照才携带该字段，避免把长期记忆发送给浏览器。
- Worker 的模型上下文增加 `agent_context`，系统提示明确要求把它当作历史参考，不能把记忆当坐标、不能绕过服务端寻路，也不能把私密记忆直接泄露到对话中。
- 旧实例未完成关系/记忆迁移时安全返回空上下文，不阻断 NPC 显示和确定性日程。

本地验证：

- 新增 `town_npc_agent_context_test.php`，验证定向关系读取和过期记忆过滤；2 项通过。
- 社交测试、Worker 12 项测试、PHP 语法检查均通过。

线上部署证据：

- `town_npcs.php` 和 NPC snapshot endpoint 已部署，备份在 `/root/deskbox-backups/agent-context-v20-20261111/`；PHP 8.2 语法通过，远程 SHA-256 分别为 `4540d9a795d84b9da90427563cd1705f80ce5c55caaade27a1fe6fd10f3265f1` 和 `329714aa3729204652aba4b1e9104dec7a59614f1558011cc886ca9f911a54d0`。
- Worker 更新为 `deskbox-npc-worker:v20`，v19 保留为 `deskbox-npc-worker-v19-agent-context-rollback`；容器运行中、重启次数为 0、健康接口 HTTP 200。
- 远程上下文复核显示 5 个 NPC 的服务快照均带 `agent_context`，普通快照带 0 个；部署后 `runs.php` 连续返回 200，未出现新的租约错误。

这一步完成了关系/长期记忆到 Agent 提示词的首版检索闭环；记忆摘要、语义检索、关系衰减和对话冷却仍属于后续社交增强项。

### 18.27 Worker 实时连接指标（2026-11-11）

为支持双账号和长连接压力验收，Worker `/health` 现在返回实时传输指标：

- `activeConnections`：当前已完成鉴权并保持连接的客户端数。
- `acceptedConnections`：进程启动以来接受的连接数。
- `broadcasts`：NPC 状态或玩家 presence 广播次数。
- `droppedConnections`：慢客户端队列溢出或发送通道关闭导致的丢弃数。

指标只读，不改变广播顺序、鉴权和慢客户端隔离策略。双连接自动化测试已断言两个客户端同时在线、至少一次 NPC 广播和指标递增；新增 8 个并发连接、24 次 NPC 广播的突发测试，确认所有 peer 收到完整事件且丢弃数为 0。

线上 `deskbox-npc-worker:v21` 已部署，v20 保留为 `/root/deskbox-npc-worker-v20-realtime-metrics-rollback`；容器 `running`、`restart_count=0`，健康接口返回 `realtime` 四项指标且初始值为 0。部署备份在 `/root/deskbox-backups/worker-v21-realtime-metrics-20261111/`。

### 18.28 v21 → v20 → v21 线上回滚演练（2026-11-11）

为验证 Worker 镜像可以在不改动数据库和配置的情况下回退，本次执行了一次短时真实线上演练。演练使用已经保留的 `deskbox-npc-worker-v20-realtime-metrics-rollback` 容器，结束时恢复到 v21；没有修改模型开关、API Key、NPC 配置或地图素材。

- 演练前 v21 容器为 `running`、`restart_count=0`，健康接口返回 HTTP 200，实时指标为 `acceptedConnections=0`、`broadcasts=57`、`droppedConnections=0`。回滚前检查文件保存在 `/root/deskbox-backups/worker-rollback-drill-20261111-140128/`，没有保存环境变量或密钥。
- 停止 v21 后启动 v20，v20 容器为 `running`、`restart_count=0`，健康接口返回 `{"ok":true,"service":"npc-worker"}`。v20 窗口内持续取得服务端 HTTP 200；切换瞬间额度接口出现 1 次 HTTP 409，随后 Worker 自动调用租约接口并恢复 HTTP 200，没有出现 `lease_required`、崩溃或重启。
- 随后停止 v20 并恢复 v21。恢复后的 v21 健康接口返回 HTTP 200，实时广播指标从 0 开始递增；额外观察 30 秒得到 78 次 HTTP 200、`lease_required=0`、HTTP 409 为 0，容器仍为 `running` 且 `restart_count=0`。
- 这次 409 是切换期间旧租约/额度请求的瞬态响应，已由现有租约刷新逻辑自动处理；没有证据表明 NPC 数据、任务或 Token 预留被回滚演练破坏。v20 和 v21 容器均保留，可继续用于人工回退。

本节完成了“可回退镜像 + 健康检查 + 租约恢复 + 回切后稳定观察”的线上演练。它仍不能替代两个真实登录客户端的 WebSocket 画面验收，也不等同于长时间双 Worker 接管压力测试。

### 18.29 双 Worker 租约竞争长窗口验收（2026-11-11）

为验证全局租约在持续竞争下不会出现双写，保留线上主 Worker `deskbox-npc-worker:v21`，临时启动第二个相同镜像 `town-worker-2`，只改变 Worker ID 和本地健康端口。第二个容器没有使用独立数据库连接或直接写库；测试结束后已停止并删除。

- 线上竞争窗口约 5 分钟，汇总文件保存在 `/root/deskbox-backups/worker-dual-pressure-long-20261111-142508/dual-summary.txt`。主 Worker 全程 `running`、`restart=0`，日志中有 737 次 HTTP 200、0 次 HTTP 409、0 次 `lease_required`、0 次致命错误。
- 第二个 Worker 全程 `running`、`restart=0`，日志中有 350 次 HTTP 409、0 次 HTTP 200、0 次 `lease_required`、0 次致命错误；它持续被全局租约拒绝，没有取得行动写入权。
- 两个健康端口在窗口结束时均返回 HTTP 200。主 Worker 的实时广播计数从 57 增至 84，`droppedConnections=0`；临时 Worker 的广播计数保持 0，符合只读/无租约时不推进村民的设计。
- 清理临时 Worker 后，线上只保留 v21 主容器，仍为 `running`、`restart=0`，健康接口继续返回 HTTP 200。测试没有修改 NPC 配置、地图、模型开关或 Token 密钥。

这项验收完成了第一版的双 Worker 租约竞争和短期持续压力证据；仍需两个真实账号完成客户端画面、断线重连和不同人格对话的人工验收。

### 18.30 Agent steps 服务端前置条件校验（2026-11-11）

此前 Worker 只对白名单动作和 `steps` 做格式校验，PHP 在创建任务时会重新规划路线，但不会检查模型描述的步骤顺序。现在把最后一道校验放到服务端动作事务内，避免任何 Worker 或未来客户端绕过模型校验后提交宽松计划。

- 新增 `town_npc_validate_plan_envelope()`：`steps` 限制为 1～16 步，只允许 `walk/work/rest/talk`；`walk` 必须有长度受限的目标；`work`/`talk`/`rest` 作为终止步骤，不能再跟随 `walk`；最后一步必须与根 `action` 一致。
- `required_capabilities` 只允许导航能力白名单。服务端保留模型声明，在完成权威 A* 路线后，逐项确认声明能力要么是 NPC 已有能力/库存，要么确实是该路线需要的能力；未知能力或与实际路线/库存不匹配的声明返回 `invalid_npc_plan`，不会创建任务、扣道具或写运行状态。
- 服务端仍以自己的路线、碰撞、导航版本、库存和交互点结果为准；steps 只是受约束的意图信封，不能携带坐标直接移动 NPC，也不能替代 `can_traverse`、`find_route` 或 `use_item`。

本地验证：`town_npc_navigation_test.php` 增至 13 项，覆盖合法的 walk→work、终止步骤顺序和未知能力拒绝；任务执行、社交、库存/道具和模型额度测试继续通过。Worker `DeskBox.NpcWorker.Tests` 13/13 通过，PHP 本地语法检查通过。

线上部署：`town_npcs.php` 已备份到 `/root/deskbox-backups/steps-validation-20261111-145028/` 后发布；使用线上 PHP 8.2 二进制检查无语法错误，远程 SHA-256 为 `5e4853bbcfc343fcc8af54e24e123208ecc82f074f6a1ed544d5b2e8c7e5783e`，与本地一致。宿主默认 `php` 是 7.4，不能用它判断 PHP 8.2 应用文件；站点 PHP-FPM 仍使用 `/www/server/php/82/`。Worker v21 继续 `running`、`restart=0`，健康接口 HTTP 200。

### 18.31 NPC 交谈冷却（2026-11-11）

为避免模型或固定日程在短时间内不断创建同一对 NPC 的交谈任务，服务端动作事务增加 60 秒冷却：同一 NPC 对同一目标在最近一次 `talk` 完成后再次提交会返回 `talk_cooldown`，管理员强制任务可用于调试和剧情脚本。冷却在 PHP 服务端执行，不依赖 Worker 或浏览器，因此不能被第二个 Worker 绕过。

- 冷却只针对已完成的同目标交谈，不影响走路、工作、休息和不同目标的交谈。
- 被拒绝的任务不会写入 `town_npc_tasks`、关系、记忆、事件或 Token 运行结果，也不会扣除道具和工作点。
- `town_npc_social_test.php` 增加重复交谈拒绝断言；导航、任务执行和其他社交测试继续通过。
- 线上 `town_npcs.php` 已备份到 `/root/deskbox-backups/talk-cooldown-20261111-150716/` 并发布；PHP 8.2 语法检查通过，远程 SHA-256 为 `5d4d4eff7c746956c494a3e3e2d888c343cc911bb3a8d2a496f234ecdd561c14`。

关系衰减、记忆摘要/语义检索和内容审核仍属于后续社交增强，不在本次冷却切片中假装完成。

### 18.32 双账号画面验收延期记录与模型成本切片复核（2026-11-11）

根据用户安排，双账号真实画面验收暂时不执行，放到本方案最后一批验收。延期只改变执行顺序，不改变验收要求；在完成前不得把“数据库有 5 个 NPC”“WebSocket 代码已部署”或自动化连接测试记作双账号画面通过。

保留的验收项如下：

- 两个真实登录账号同时打开共享小镇，看到相同的 5 个 NPC 快照、姓名、位置和状态。
- 玩家移动和 NPC 逐格移动能够在另一账号实时出现，旧事件不会覆盖新位置。
- NPC 对话气泡、工作/休息状态和地图改动后的重新规划在两个客户端一致。
- 断开其中一个客户端后重新连接，能用快照补齐状态并继续接收增量。
- 完成上述画面验收后，再做不同人格对话和长连接观察；双账号仍是第一版最后的人工验收项。

本次同时复核了模型成本切片的线上部署状态：

- 远程 PHP 8.2 语法检查使用 `/www/server/php/82/bin/php`；`town_model_provider.php`、`town_agent.php`、后台入口和 `036_town_model_cost_history.sql` 与本地 SHA-256 一致。
- 远程 MySQL 已存在 `town_model_provider_pricing`、`town_model_provider_revisions` 和 `town_agent_run_costs` 三张表；当前三表均为空，表示尚未配置价格或产生可计价的运行成本记录，不能据此声称已有费用金额报表数据。
- 当前 provider 仍为启用且 `monthly_quota=0`（不限额）；这只证明配置可读，不代表月额度限制已经被实际触发验收。
- `deskbox-npc-worker:v21` 容器仍为 `running`、重启次数为 0；健康接口 HTTP 200，实时指标为 `acceptedConnections=4`、`broadcasts=169`、`droppedConnections=0`、当前活动连接 0。该指标来自 Worker 进程，不等同于两个真实账号画面验收。

截至本记录，模型成本代码和数据库结构已部署，供应商价格历史、费用金额和报表长期归档仍需在配置价格并产生真实运行记录后补验；双账号、断线重连和最终五人同屏人工验收继续保留到最后。

### 18.33 Agent 报表费用明细与供应商修订历史（2026-11-12）

为减少线上问题排查时必须直接查数据库的情况，后台 `Agent 模型` 页面新增两块只读明细：

- **费用明细**：展示时间、村民、输入/输出 Token、调用时保存的输入/输出单价、金额和币种。金额来自 `town_agent_run_costs` 的不可变快照，不会因为后来修改价格而重算历史记录；没有价格或没有模型调用时显示“暂无费用记录”。
- **供应商配置修订历史**：展示保存时间、供应商、动作、模型、月额度、启用状态和输入/输出价格。快照来自 `town_model_provider_revisions`，不包含 API Key 或密文。

本地验证：`town_model_cost_test.php` 增至 8 项，覆盖报表费用行和供应商修订行；Agent 可观测性、模型额度、社交和任务执行测试继续通过；`town_agent.php`、后台入口和测试文件 PHP 语法检查通过。

线上验证：

- 远程 PHP 8.2 语法检查通过，线上文件与本地 SHA-256 一致。
- 部署前备份位于 `/root/deskbox-backups/model-history-20261112-000549/`。
- 线上 Worker 未重启，仍使用 `deskbox-npc-worker:v21`；本次只更新 PHP 报表读取代码。
- 线上三张成本/历史表当前均为 0 行，故已验证页面和读取链路可部署，但尚未产生可展示的费用明细或供应商历史数据；这项数据验收仍待管理员配置单价并产生新的模型运行。

本切片把“完整模型供应商管理”推进到可查看历史和逐次费用，但没有改变额度口径（仍按 Token 数限制），也没有提前完成双账号 WebSocket 画面验收。双账号真实登录、断线重连、五人同屏和不同人格对话策略仍按用户要求留到第一版最后。

### 18.34 长期记忆确定性摘要压缩（2026-11-12）

为避免村民长期交谈记忆无限增长，完成了首版不依赖模型的记忆压缩：

- 每个村民保留最近 12 条原始 `conversation` 记忆。
- 当原始交谈记录超过 24 条时，较早记录压缩成一条 `conversation_summary`，内容限长 4000 字符、重要度为 3；重复压缩会更新同一条摘要，不会持续创建摘要垃圾记录。
- 摘要在现有 `town_npc_agent_context()` 中和最近记忆一起受控读取，普通用户快照仍不会暴露记忆。
- 压缩发生在交谈完成事务内；如果旧安装尚未有记忆表，原有交谈写入仍按兼容路径运行。

本地 `town_npc_social_test.php` 已增加摘要压缩和 Agent 上下文断言并通过；`town_npcs.php` PHP 语法检查通过。线上文件已部署，PHP 8.2 语法检查通过，备份位于 `/root/deskbox-backups/memory-compaction-20261112-001748/`。本次只更新服务端 NPC 逻辑，没有重启客户端或 Worker。

这一步完成了“记忆摘要”的确定性首版，不声称已完成语义向量检索、关系衰减或内容审核；这些仍需单独实现和验收。双账号真实画面验收继续留到最后。

### 18.35 导航进程缓存与版本失效（2026-11-12）

为降低 NPC 多次寻路时对素材表的重复读取，导航服务增加了有界进程内网格缓存：

- 缓存按 `navigation_version` 命名空间；同一版本内复用已解析的素材语义，最多保留 20,000 个格子。
- `town_navigation_rebuild()` 提升世界导航版本后，下一次查询自动清空旧版本缓存；地图素材修改必须继续走现有重建接口，不能直接改表后期待缓存立即变化。
- SQLite 测试兼容路径补齐了素材坐标字段，版本重建后会重新读取阻挡素材。

本地 `town_npc_navigation_test.php` 已增加“重建前缓存、重建后失效”的断言并通过 15 项；线上 `town_navigation.php` 已部署，PHP 8.2 语法检查通过，备份位于 `/root/deskbox-backups/navigation-cache-20261112-002746/`。该切片完成了首版区块/网格缓存的版本失效基础，但尚未实现跨区块高度连接、桥梁建造排队和复杂交互点调度。

### 18.36 Agent 记忆相关性检索（2026-11-12）

在确定性摘要压缩之上，Agent 上下文增加了轻量相关性检索：

- 服务端只读取当前 NPC 的未过期记忆，按重要度、查询完整匹配和关键词匹配排序，最多返回 12 条；每条内容仍限制 1000 字符。
- Worker 快照会用村民短期目标、长期目标、职业和当前状态组成受限查询词，使“农田浇水”“集市采购”等记忆优先进入对应计划上下文。
- 普通用户快照和 Web 页面仍不包含 `agent_context`；检索只在服务令牌快照中执行，不改变模型密钥和隐私边界。
- 这是无需向量数据库的首版相关性检索，中文按分隔词/完整短语匹配；语义向量检索仍是后续增强项。

本地 `town_npc_agent_context_test.php` 增至 3 项，验证过期过滤和查询相关记忆排序；社交、导航测试继续通过。线上 `town_npcs.php` 已部署，PHP 8.2 语法检查通过，备份位于 `/root/deskbox-backups/memory-retrieval-20261112-003938/`。本次没有重启 Worker。

### 18.37 关系与记忆管理分页（2026-11-12）

村民管理页的关系和长期记忆表改为分页读取，每页 50 条，前后页通过 URL 参数保持当前页面状态。服务端查询同时限制页码和偏移范围，避免管理员页面一次加载无限增长的历史记录；原有关系校准和记忆清除操作不变。

本地 PHP 语法和社交测试通过；线上 `town_npcs.php`、后台入口已同步并通过 PHP 8.2 语法检查，备份位于 `/root/deskbox-backups/social-pagination-20261112-004701/`。这完成了后台关系/记忆分页基础，批量编辑、关系衰减和内容审核仍未完成。

随后补充了村民筛选：关系和记忆分页支持按指定 NPC 过滤，分页链接会保留筛选条件。线上更新备份位于 `/root/deskbox-backups/social-filter-pagination-20261112-005108/`，PHP 8.2 语法检查通过。

### 18.38 NPC 对话传输安全过滤（2026-11-12）

在语义审核规则尚未确定前，先完成不依赖业务判断的传输安全层：服务端会删除控制字符，限制对话长度，并拒绝脚本、iframe、对象嵌入、`javascript:` 和 HTML data URI。被拒绝的文本不会创建任务、写入关系/记忆或广播到客户端；正常中文对话行为保持不变。

本地社交和任务测试通过，新增对可执行标记拒绝的断言；线上 `town_npcs.php` 已部署并通过 PHP 8.2 语法检查，备份位于 `/root/deskbox-backups/dialogue-moderation-20261112-005856/`。这只是输入安全过滤，不代表完成了敏感内容分类、审核策略或人工复核流程；这些规则仍需单独确认。

### 18.39 待用户确认的业务规则（2026-11-12）

以下两项会改变线上关系和钱包数据，暂不自行写入默认规则：

- 关系衰减：可选每 7 天未互动向 0 衰减 1 点、完全不衰减，或每天衰减 1 点。确认后再增加 Worker/服务端的定时或惰性结算，并补充线上数据验收。
- 玩家经济：可沿用现有水族馆金币完成共享小镇种植、成长、收获、购买和领取，也可以建立独立小镇货币，或暂缓玩家经济。确认前只保留现有 NPC 农田/集市/工坊世界结果，不修改玩家钱包。

双账号 WebSocket 画面、断线重连、五人同屏和不同人格线上对话仍按用户安排放在第一版最后；它们不能用数据库快照或自动化 WebSocket 单元测试替代。

### 18.40 NPC 路线方向动画（2026-11-12）

客户端 NPC 路线插值现在会根据每个相邻网格点计算上、下、左、右方向；使用默认人物图集时切换对应 `walk-*` 动画，路线完成后停止动画。自定义人物素材仍使用其静态首帧，避免假设未知图集的帧布局。服务端坐标和路线仍保持权威，动画只负责显示。

本地 `node --check town.js`、`verify-town-prototype.mjs` 和 `verify-town-realtime.mjs` 通过。按仓库要求已停止旧 DeskBox，重新构建项目并启动唯一 canonical Debug 实例：`src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。构建保留 9 个既有警告、0 个错误；当前确认只有一个该路径进程运行。

### 18.41 楼梯连接方向校验（2026-11-12）

导航 A* 现在读取楼梯素材的 `connections` 配置：空配置保持兼容并允许通行；配置了方向后，进入和离开楼梯都必须匹配 north/south/east/west 及其 up/down/left/right 单字母别名。这样素材可以表达单向或竖向高度转换入口，未知素材仍按阻挡处理。

本地导航测试增至 17 项，覆盖方向允许和拒绝；任务执行测试继续通过。线上 `town_navigation.php` 已部署并通过 PHP 8.2 语法检查，备份位于 `/root/deskbox-backups/stairs-connections-20261112-011224/`。跨高度状态建模、复杂桥梁建造排队和真实地图素材验收仍未完成。

### 18.42 村民批量管理切片（2026-11-12）

根据用户安排，双账号真实画面验收继续留到第一版最后；本阶段先推进不依赖画面验收的后台运维能力。村民管理页现在支持勾选多个村民并批量设置状态：

- 批量暂停 Agent / 恢复 Agent。
- 批量停用村民 / 启用村民。
- 单次最多 100 个村民；空选择、非法 ID、超过上限和包含不存在记录的请求均由服务端拒绝。
- 服务端只接受 `agent_paused` 和 `enabled` 两个白名单字段，ID 会去重并校验为 32 位十六进制值；所有选中记录必须存在，避免旧页面误操作部分数据。
- 状态更新在一个数据库事务中执行；每个村民写入一条 `town_npc_activity_logs` 审计记录，记录字段、目标值、管理员账号和 `admin_bulk` 来源。失败时整个批次回滚。
- 页面仍保留每个村民的单项暂停/恢复、启用/停用和删除操作；批量表单与表格复用同一管理会话和 CSRF 校验，不向普通用户开放。

本地验证：新增 `town_npc_bulk_admin_test.php`，覆盖批量暂停、每个 NPC 的审计记录、未知 NPC 拒绝和非法字段拒绝；该测试、社交、导航（17 项）、任务执行（15 项）和 Agent 可观测性测试均通过；三个 PHP 文件通过 PHP 语法检查。

线上发布补充：3 个相关文件已同步到 `/www/wwwroot/sltbox.2leaves.cn`，发布前备份位于 `/root/deskbox-backups/npc-bulk-admin-20261112-012740/`。远程 PHP 8.2 语法检查通过；远程 SHA-256 与本地一致：`town_npcs.php` 为 `f886502b8327b6c1cf1d4e21c8341fe3f0ad3f46a693b5450ec08acbe9b9d749`，`admin_web.php` 为 `9d0450162d1a4ddd3dc49a5b3691327fd1897ef06d10adc997099f3865fd89ed`，`api/admin/index.php` 为 `64c74f39e679d89c98115ebf94c539b3d4c8ef5aa520dc5208f0cedc0571dc77`；未使用管理员页面会话做真实批量点击，因此后台画面操作仍待后续人工确认。双账号 WebSocket 画面、断线重连、五人同屏和不同人格线上对话仍按用户要求最后验收。

### 18.43 NPC 跨高度导航与运行高度（2026-11-12）

本切片把素材导航里的 `height` 从配置字段接入实际 NPC 路线和任务执行，不改变六层素材显示层级；显示层与地形高度仍是独立概念。

| 项目 | 状态 | 当前实现和边界 |
| --- | --- | --- |
| 高度规则 | 已完成 | 同高度普通地面保持可通行；相邻格高度差为 1 时，至少一侧必须是 `stairs`，并同时通过楼梯方向校验；高度差超过 1 或没有楼梯时拒绝该边。 |
| A* 路线 | 已完成 | 服务端规划路线节点现在包含 `x`、`y`、`height`，高度由服务端素材语义导出，模型不能直接指定执行高度。 |
| 逐格执行 | 已完成 | 推进任务时复核当前位置和下一格的高度、相邻步长、楼梯转换、能力和阻挡；高度不一致时停止任务并记录失败原因，不会跨过未校验的高度变化。 |
| 运行状态持久化 | 已完成 | 新迁移 `037_town_npc_runtime_height.sql` 增加 `town_npc_runtime_states.height SMALLINT NOT NULL DEFAULT 0`；动作启动、行走和管理员位置设置同步高度；快照、任务响应、事件和活动日志包含高度。滚动升级期间兼容尚未执行 037 的表结构。 |
| 地图改变后的路线 | 已完成首版 | 继续使用导航版本重规划；新路线重新读取各格高度。无法重规划时任务失败并保持在当前已校验位置。 |
| 客户端立体显示/玩家高度 | 待实施 | 本切片只建立 NPC 权威导航高度，不新增立体投影或修改玩家高度；还需在真实地图放置楼梯素材后做可视化验收。 |

本地测试：`town_npc_height_test.php` 8 项检查通过，覆盖无楼梯不可达、楼梯连接、高度路线节点、逐格上楼和运行高度落库、超过一层不可达。全部 8 个 `town_npc*_test.php` 均通过，包含原有导航 17 项、任务执行 15 项和库存预约 8 项；相关 PHP 文件通过本地语法检查。

线上部署证据：`api/_app/town_npcs.php`、`api/_app/town_navigation.php`、`database/037_town_npc_runtime_height.sql` 已通过 FTP 同步，三项文件完整回读后 SHA-256 与本地一致。原 PHP 备份位于 `/root/deskbox-backups/npc-height-20261112-015918/`；数据库备份表为 `town_npc_runtime_states_bak_20261112015918`。远程数据库已执行等价的 037 加列操作，执行前后均为 5 行，`height` 类型确认是 `smallint`，当前 5 人高度均为 0。随后补充旧导航字段兼容和管理员位置校验，第二次发布前备份位于 `/root/deskbox-backups/npc-height-alias-20261112-021818/`；回读哈希为 `town_npcs.php=80c4fd9251f4b8f0c236b266ca4d93261deee734670b068fef04526965464d45`、`town_navigation.php=d041eab76cf416bb8d435d9c3556f4f060f884e0de9d79b23b0893ecf2eada1f`。Worker 仍为 `deskbox-npc-worker:v21`，运行中、重启次数 0；本次未改变 Worker 镜像。

尚未完成的验证：此次没有使用真实楼梯素材做人工画面验收，也没有新增或移动线上素材/NPC。匿名导航接口返回 401 只证明认证边界存在，不能代替登录后的路线查询。线上主机 PHP 8.2 运行时语法检查和真实跨高度动作尚需补证，文件哈希、数据库字段和容器状态不得作为画面验收证据。

### 18.44 无模型配置下的人格化确定性对话（2026-11-12）

为完成“模型不可用时仍能运行”的闭环，同时避免把关系规则或费用规则擅自写死，Worker 现在会在确定性回退任务中补充短对话：

- 只对 `talk` 动作补齐对话；模型已经返回非空 `dialogue` 时原文保留。
- 根据 `speech_style` 选择温柔鼓励、简洁直接、谨慎观察三种风格；没有说话方式时按职业使用农夫、商人、工匠的默认句式。
- 对话仍经过服务端长度和安全过滤，关系、记忆、事件和 WebSocket 广播继续由 PHP 服务器处理。
- 这使无模型配置时的确定性日程也能产生可审计的对话事件，但不等同于完成模型驱动的人格对话策略。

本地验证：`DeskBox.NpcWorker.Tests` 从 13 项增加到 15 项，新增两项覆盖不同说话方式产生不同文本、以及保留模型已有文本；Worker Debug 构建 0 警告、0 错误。按仓库要求已重启唯一 canonical Debug 客户端，当前运行路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。

线上部署：Worker 镜像更新为 `deskbox-npc-worker:v22`，构建使用当前 `Program.cs`、`TownRealtimeHub.cs` 和 Dockerfile；健康接口返回 `ok=true`，容器 `running`、重启次数为 0。v21 已保留为 `deskbox-npc-worker-v21-deterministic-dialogue-rollback`，部署备份位于 `/root/deskbox-backups/worker-v22-deterministic-dialogue-20261112023000/`。本次没有修改模型配置、Token、NPC 资料、地图或关系数据。

仍待人工验证：两个不同人格在真实登录客户端中显示的对话气泡、模型开启后的风格差异、断线重连和五人同屏仍按用户安排放到最后；关系衰减和玩家经济规则仍需用户选择后再实现。

### 18.45 素材导航语义可编辑（2026-11-12）

共享小镇素材列表的编辑行此前把 `navigation` 作为隐藏字段回传，管理员只能在新增素材时填写导航 JSON，不能直接修改已存在素材的通行语义。本次将编辑行改为可见的“导航 JSON”文本框，继续复用现有服务端白名单和 JSON 校验：

- 可直接修改 `plain`、`blocked`、`stairs`、`water`、`bridge` 以及阻挡、方向、高度和所需能力字段。
- 保存仍通过原有 CSRF、管理员权限和素材事务；未知或非法导航类型继续拒绝，不会把未知素材当成道路。
- 修改后仍需点击导航重建，运行中的 NPC 才会在导航版本变化时重新规划；页面没有绕过服务端碰撞校验。

本地验证：`web_admin_service_test.php`、`town_asset_service_test.php` 和 `town_npc_navigation_test.php`（17 项）通过；`api/admin/index.php`、`admin_web.php` 通过 PHP 语法检查。线上 `api/admin/index.php` 已 FTP 回读校验，SHA-256 为 `0eba15e3525067b20369d869c8c231c9fe252d7bdf528446a00bd2fedc3dad6b`，发布前备份位于 `/root/deskbox-backups/navigation-admin-20261112-024036/`。

尚未完成：登录管理后台实际修改一项楼梯/河道素材并在真实地图中观察 NPC 重规划；该操作属于最终人工验收批次，继续按用户要求留到双账号和五人同屏之后。
