# 共享小镇村民 Agent 与动态通行系统开发方案

## 1. 目标

在现有共享小镇中加入可配置的村民和 Agent 能力：

- 管理员可以在 Web 端添加、编辑、停用和删除村民。
- 每个村民拥有姓名、外观、人格、家庭背景、生活目标、日程和人际关系。
- 村民能够自主行走、工作、休息、交谈、使用道具和协同完成任务。
- 地图素材不仅描述外观，还描述占地、碰撞、地形高度、通行条件和可用道具。
- 地图素材经常更换时，服务器能够重新计算通行规则，并使村民重新规划路线。
- AI 可以决定“想做什么”和“是否需要道具”，服务器决定“能不能做”和“如何执行”。
- 模型、模型供应商、Agent 调用频次和行为策略可以在 Web 端配置；Token 费用统计和模型连接测试暂缓。

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
- 模型供应商、模型密钥、调用频次策略和审计日志。
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
├── account_id / owner_scope
├── home_asset_id
├── home_tile_x
├── home_tile_y
├── occupation
└── public_scope
```

共享小镇必须与现有 SltBox 账号体系关联。每个登录账号进入小镇时都使用自己的认证身份，并拥有自己的玩家角色、玩家位置和装饰库存；玩家可以在服务器校验后自行放置、移动和删除装饰。NPC 是共享世界中的独立实体，但其配置、运行状态、记忆和家必须按 `npc_id` 持久化，不能写入某个浏览器本地缓存。

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

### 5.4 家、环境观察和空间记忆

每个 NPC 必须配置自己的家：

```text
NpcHome
├── npc_id
├── home_asset_id       家的素材 ID
├── home_asset_name     配置时保存的素材名称快照
├── home_tile_x
├── home_tile_y
└── rest_interaction_point
```

工作或外出任务完成后，NPC 默认生成回家休息任务；回家路线不可行时进入 `blocked_home` 等待状态并记录原因。NPC 观察环境时，服务器通过素材 ID、素材名称、分类、坐标和导航语义生成可审计的观察结果，模型不能只凭图片猜测建筑用途。观察结果写入 NPC 记忆，包括：

- 房屋、商店、农田、工坊等可识别地点的素材 ID、名称和坐标。
- 重要装饰、工作点、交互点和阻挡物的素材 ID、名称和坐标。
- 观察发生时间、地图导航版本、记忆重要度和来源事件。
- 后续路线或任务使用的地点别名，例如“集市”“我的家”“周岩的工坊”。

地图素材被替换、移动或删除后，相关空间记忆必须标记为过期或待确认；NPC 重新观察后才能继续把旧坐标当作可靠目标。

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

村民对话必须是两个人面对面的交谈，不是只改变两个状态字段或在远处生成气泡。推荐流程：

1. 由任务、关系、日程、附近事件或玩家行为产生对话候选，并记录发起者 `initiator_npc_id`。
2. 发起者根据自己记忆中的对方坐标生成前往目标的任务；服务器再读取目标当前权威坐标并校验路线。
3. 发起者逐格移动到对方可交互距离，并调整朝向；只有双方都在交互范围内才进入 `talk`。
4. 若目标已经回家、正在外出或移动到别处，服务器记录 `conversation_target_missing`，在发起者记忆中保存“上次寻找对方的时间、已知坐标、未找到原因”，然后重新进入 Agent 决策，不把失败当作成功对话。
5. 对话生成时注入双方人格、关系、近期记忆、空间记忆和当前坐标；生成有限轮次的双向台词。
6. 对话完成后，把摘要、原始短文本、双方坐标、发起者、目标、地图版本、情绪变化和关系变化分别写入双方记忆及事件日志。
7. 下一次寻找同一 NPC 时优先使用记忆中的地点和坐标，再通过实时快照确认；坐标失效时触发重新观察和重新规划。
8. 对话拥有可配置冷却和模型调用间隔，不能因为每次靠近或每个模拟 tick 无限调用模型。

村民之间的“协同工作”通过任务和资源状态实现，而不是让多个模型自由聊天后自行修改世界。

## 9. 数据库设计

建议增加以下表：

```text
town_npcs
  npc_id, display_name, appearance_asset_id, portrait_asset_id,
  account_id, occupation, home_asset_id, home_asset_name,
  home_tile_x, home_tile_y, home_interaction_json,
  enabled, version, created_at, updated_at

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
  subject_npc_id, asset_id, asset_name, tile_x, tile_y,
  navigation_version, embedding_ref, source_event_id, created_at, expires_at

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
PUT    /api/admin/npcs/{npc_id}/home
PUT    /api/admin/npcs/{npc_id}/decision-policy
PUT    /api/admin/npcs/{npc_id}/economy
GET    /api/admin/npcs/{npc_id}/transactions

GET    /api/admin/models
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
GET  /api/v2/town/players/me
POST /api/v2/town/players/me/decorations
PUT  /api/v2/town/players/me/decorations/{object_id}
DELETE /api/v2/town/players/me/decorations/{object_id}
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
- 每个 NPC 的决策间隔、环境观察间隔和对话冷却。
- 全局最大决策频次和单 NPC 每小时最大决策次数。
- 启用/停用。

API Key 只能写入服务端受保护的 secret 存储，Web 页面不回显完整密钥。

第一版不提供模型连接测试按钮，也不在后台显示 Token 费用报表；模型请求失败会由 Worker 记录运行错误并回退确定性日程。模型调用频次配置必须保存到 NPC 决策策略，并由 Worker 和服务端共同执行冷却。

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
- 绑定 SltBox 账号，建立每个玩家的角色、位置和装饰所有权。
- 增加 NPC 家、回家休息、初始钱包/库存和交易账本。
- 客户端显示 NPC、名称、状态和移动动画。
- 实现无 AI 的确定性日程。
- 实现服务器权威移动和路线失效重规划。

验收标准：村民可以按日程在家、工作区和公共区域之间移动，地图修改后不会走旧路线。

### 阶段三：单村民 Agent

- 引入 .NET Worker、Semantic Kernel 和 LiteLLM。
- 实现模型配置、调用频次策略和 Agent 运行日志。
- 实现严格 JSON Action Schema。
- 允许 Agent 选择工作、休息、交谈和使用道具。
- 所有行动经过服务器验证。

验收标准：模型故意返回非法位置、非法道具或穿越悬崖时，服务器拒绝执行并记录原因。

### 阶段四：多人格和村民对话

- 增加家庭背景、目标、关系和记忆。
- 增加素材名称/坐标观察、地点记忆和过期重观察。
- 增加发起者寻路、面对面交互、目标缺失记忆和下一步重决策。
- 增加对话摘要和长期记忆。
- 增加村民之间的任务协同。

验收标准：两个村民可以根据关系和目标产生不同对话，并通过任务状态协同工作。

### 阶段五：管理和运营

- 完成 Web 村民管理页面。
- 增加暂停、恢复、强制任务、重置记忆和模型切换。
- 增加延迟、失败率、阻挡原因、交易和找人失败记录查询。
- 增加批量导入、导出和配置版本。

Token 使用量、费用金额、价格历史和模型连接测试不属于本阶段，按 16.2 暂缓。

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
- 模型调用间隔、观察间隔、对话冷却和每小时频次上限。
- 无模型或模型失败时回退确定性日程。
- 村民余额不足时选择赚钱任务，不产生负余额。

### 客户端测试

- NPC 快照、增量变化和旧会话响应拒绝。
- NPC 移动插值和路线取消。
- 地图变更后的 NPC 状态刷新。
- NPC 对话气泡和状态显示不遮挡编辑菜单。
- 不同 SltBox 账号只显示自己的玩家角色、位置和装饰权限。
- 对话发起者移动到对方坐标，双方到达交互距离后才显示对话。

### 线上安全测试

- 普通账号不能修改 NPC 配置。
- 普通账号只能操作自己的玩家角色和装饰，不能修改其他用户对象。
- Agent 不能直接调用数据库。
- 模型密钥不出现在 WebView、日志和响应中。
- 每个 NPC 的调用频次由服务端策略限制。
- 所有管理动作和 Agent 动作保留审计记录。

## 15. 主要风险和处理方式

| 风险 | 处理方式 |
| --- | --- |
| 模型让村民穿墙或瞬移 | 服务器权威寻路和行动校验 |
| 素材只配置了图片没有语义 | 未配置素材默认不可通行 |
| 每个村民频繁调用模型导致调用过密 | 事件触发、决策间隔、对话冷却和批量决策；费用报表不作为第一版依据 |
| 地图频繁变化导致路线失效 | `navigation_version` 和区块级缓存失效 |
| 多个村民抢同一工作点 | 交互点预约和任务锁 |
| 对话内容无限增长 | 近期记忆加长期摘要 |
| PHP 请求被 AI 调用阻塞 | Agent 使用独立 Worker 队列 |
| Agent 服务故障影响小镇 | NPC 回退到确定性日程，玩家地图继续可用 |
| 本地模型质量不稳定 | 模型网关支持按任务切换云端模型和本地模型 |

## 16. 第一版必须完成范围

第一版必须完成以下闭环，后文的旧范围描述和“后续再增加”的表述以本清单为准：

1. **账号和玩家角色**：共享小镇必须使用 SltBox 账号认证。每个账号进入小镇都有自己独立的玩家角色、位置、外观/身份和装饰库存；玩家可以在服务器校验后自行部署、移动和删除装饰，不能看到或修改其他账号的私有库存。
2. **NPC 基础运行**：支持 5～10 个村民、农夫/商人/工匠三种职业，以及行走、工作、休息、交谈四种行动。玩家和 NPC 共用服务器权威通行规则。
3. **NPC 经济**：玩家经济、玩家钱包、玩家购买和玩家领取第一版暂不实现。NPC 必须有可在 Web 端初始化的钱包、库存和交易记录；村民可以互相买卖，余额不足时通过工作、服务、生产或其他已配置的赚钱任务获得收入，并把收入、支出、交易对象和原因写入账本。
4. **环境识别和空间记忆**：NPC 必须按素材 ID、素材名称、分类、导航语义和坐标识别房屋、商店、农田、工坊及重要装饰；观察结果写入记忆，后续任务可以使用“商店”“某人的家”等地点别名。素材变更后旧记忆必须失效或待重新确认。
5. **个人住房**：每个 NPC 必须配置自己的家，包括家的素材 ID、素材名称快照、坐标和休息交互点。工作完成后默认回家休息；回家失败要记录阻挡原因并重新决策。
6. **模型调用频次控制**：Web 后台必须能按 NPC 或全局配置 Agent 决策间隔、最小调用冷却、环境观察间隔、对话冷却和必要时的每小时调用上限。Worker 必须在服务端执行这些限制；不能按每帧或每个 tick 调用模型。
7. **面对面对话和寻找失败**：对话由一方发起，发起者根据记忆中的目标坐标前往对方，双方进入交互距离并面向后才开始对话。对话内容、双方坐标、发起者、目标、地点和地图版本写入双方记忆；找不到对方时写入“目标缺失/最后已知坐标”记忆，再由 Agent 决定下一步。
8. **导航和道具**：支持普通地面、阻挡物、楼梯、河道/桥梁及梯子、桥梁工具等能力；地图素材更换后旧路线失效并重新规划。
9. **模型降级**：没有模型或模型不可用时，NPC 仍按确定性日程运行。第一版保留行动、错误和事件日志用于排查，但暂停 Token 使用报表、费用计算、供应商价格历史和“测试模型连接”接口，不把包月模型换算成费用。

第一版暂不包括：玩家经济、玩家钱包交易、Token/费用运营报表、模型 API 正常性验证、关系衰减、内容审核分类、桥梁永久建造队列和复杂 3D 导航。上述项目不得被旧章节中的“已实现”描述重新列为第一版完成项。

### 16.1 第一版新增数据和管理要求

```text
town_player_characters
  user_id, display_name, appearance_asset_id, created_at, updated_at

town_npc_homes
  npc_id, home_asset_id, home_asset_name, tile_x, tile_y,
  interaction_json, updated_at

town_npc_wallets
  npc_id, balance, initialized_by, updated_at

town_npc_transactions
  transaction_id, seller_npc_id, buyer_npc_id, item_id,
  quantity, amount, reason, created_at

town_npc_decision_policies
  npc_id, decision_interval_seconds, observation_interval_seconds,
  dialogue_cooldown_seconds, max_decisions_per_hour, updated_at
```

实现时应优先复用已有 `town_player_positions`、`town_objects.owner_user_id`、NPC 库存、工作结果和记忆表；如果已有表能够承载字段，不重复创建同名表。Web 村民编辑页必须提供住房、初始余额、初始库存和调用频次配置；交易记录、赚钱任务、地点记忆和找人失败记录必须可查询。

### 16.2 暂缓的模型运营范围

当前使用包月模型，无法从 Token 数可靠推导费用。因此本版只保留模型运行所需的最小技术字段（请求状态、错误、必要的输入/输出 Token 和耗时），暂停以下开发和验收：

- Token 使用量报表和费用金额报表；
- 输入/输出单价、币种、供应商费用历史和成本快照；
- “测试模型连接”或其他 API 是否正常的后台验证按钮；
- 按费用金额限额、费用预警和供应商成本比较。

模型调用频次控制属于 NPC 行为稳定性，仍是第一版必须完成项；它不等同于 Token 费用统计。

## 17. 实施状态与续做记录（压缩权威版，2026-11-14）

本节替代此前 17.x～19.x 的逐次流水账。旧记录中的实现、测试和部署结果已经合并；后续开发优先以本节的状态、证据和未完成清单为准。设计章节 1～16 保留作为架构依据，但其中的旧“尚未完成”描述若与本节冲突，以本节为准。

### 17.1 当前总体状态

| 能力域 | 当前状态 | 已完成内容与证据 | 仍需注意 |
| --- | --- | --- | --- |
| SltBox 账号与玩家角色 | 已完成 | 角色、位置、外观和装饰按 `user_id` 隔离；服务端所有权校验；两个真实账号已确认互相可见但不能修改对方装饰。 | 新增玩家相关功能必须继续走账号所有权校验。 |
| 玩家实时同步 | 已完成 | WebSocket 玩家/NPC 增量、断线重连、旧会话丢弃和位置持久化已实现；两个客户端已确认移动同步、重连后玩家和 5 个 NPC 恢复。 | 继续保持服务端权威，客户端只做插值和显示。 |
| NPC 资料、人格、日程 | 已完成 | 5 个样例村民、职业、人格、家庭背景、目标、日程、运行状态和居民详情接口已部署；详情和聊天历史有权限投影。 | 模型自主选题和长期人格差异的线上证据仍不作为第一版硬门槛。 |
| NPC Worker | 已完成 | 独立 .NET Worker、全局租约、确定性回退、任务执行、日志、Docker 运行和健康检查已完成；线上当前运行 `deskbox-npc-worker:v43-npc-stats-story`，容器状态为 running、重启次数为 0，健康接口返回 `ok=true`。v43 包含 NPC 数值/故事事件上下文、规则事件生成、模型草案白名单校验、活动事件恢复和事务结算；v42 保留为停止状态回滚容器。 | 长时间多 Worker 压力测试不作为当前 UI/素材任务的阻塞项。 |
| 导航与动态通行 | 已完成 | `plain/blocked/stairs/water/bridge`、高度、梯子/桥梁能力、A*、导航版本失效、地图对象变更后重规划已实现；用户已实际确认临时阻挡后 NPC 绕行，临时对象已清理，导航版本保持递增。 | 未配置导航语义的素材默认不可通行。 |
| NPC 家、环境观察和空间记忆 | 已完成基础闭环 | 家的素材 ID/名称/坐标/休息点、工作后回家、阻挡等待、素材名称和坐标观察、导航版本导致记忆过期已实现并有服务端测试。 | 后续若改变记忆模型，必须保留旧坐标失效和来源事件。 |
| NPC 经济 | 已完成基础闭环 | Web 初始化余额/库存、NPC 交易、收入/支出账本、余额不足后的合法赚钱任务已实现；玩家经济仍未实现。 | 继续避免把玩家钱包加入第一版。 |
| NPC-NPC 面对面交谈 | 已完成 | 发起者按目标记忆寻路到相邻格、双方朝向校验、对话和坐标双向记忆、目标缺失事件和下一轮重决策已实现。 |  |
| 玩家面对面交谈 | 已完成并线上验收 | 休息 NPC 才接受邀请；玩家/NPC 必须相邻并面对；对话气泡显示实际台词；同一会话可连续发送，无需解除邀请；两个客户端已确认气泡同步。 |  |
| 居民面板与交互 UI | 已完成主要切片 | 居民详情、资料/聊天分页、定位/取消定位、邀请交谈/跟随、状态上下文栏、素材抽屉、名字/状态开关、聊天输入和气泡已接入。 | 仍需在真实窄屏/桌面窗口做最终视觉回归，不能用静态脚本代替。 |
| 素材自动分析与动态排序 | 已完成首版闭环 | PNG Alpha 包围盒、锚点、占地、碰撞、类别、16×16 图集候选区域分析；`y_sort/background/foreground/manual` 动态深度排序；Web 后台自动识别按钮和排序模式已上线。房屋 `split` 模式、`split_lines` 保存链路、独立分段纹理、前景/中段脚底排序/背景三段深度和前景命中检测已实现并部署；自动识别结果支持最多 500 个候选一次性事务保存为可编辑草稿；桌面编辑模式新增连续铺设笔刷，按网格拖动逐个提交服务器校验。`town-source-fangwu01`（128×176、拆分线 96/144）已在原生 Debug WebView 完成人物后方、中段墙体、屋檐前方三条路径验收，三段连续可见且遮挡关系正确。 | 自动命名质量和批量确认预览仍可继续优化，不阻塞第一版。 |
| 小镇素材本地化缓存 | 已完成客户端首版 | `SharedTownSession` 读取世界返回的 `asset_release.release_version/config_sha256`，以 `catalog-{release}-{hash}.json` 持久化素材目录；目录命中时跳过 `catalog.php` 请求。现有图集 PNG 继续按 SHA-256 文件名缓存并校验 PNG 签名、尺寸和哈希；发布版本或配置哈希变化时重新取得目录，图集只在本地缺失/损坏/哈希变化时下载。缓存目录写入采用临时文件原子替换，损坏或无效目录自动回退认证下载。 | 首次启动仍需下载全部发布图集；用户清理 DeskBox 数据目录后会重新建立缓存。 |

### 17.2 关键实现文件

服务端和数据库：

- `server/deskbox-api/api/_app/town_npcs.php`：NPC 行动、家、观察、空间记忆、面对面交谈和目标缺失。
- `server/deskbox-api/api/_app/town_navigation.php`：高度、通行能力、路线和导航版本。
- `server/deskbox-api/api/_app/shared_town.php`：玩家/素材对象权限、变更游标和导航失效。
- `server/deskbox-api/api/_app/town_assets.php`：素材元数据、导航语义和排序模式。
- `server/deskbox-api/api/admin/index.php`、`api/admin/views/`、`api/admin/town-assets.js`、`server/deskbox-api/api/_app/admin_web.php`：Web 管理后台和素材保存调用链。
- `server/deskbox-api/api/admin/town-assets-scan.js`：透明网格识别模块。
- 主要迁移：`028_shared_town_npc_v1.sql`、`029_town_agent_observability.sql`、`034_shared_town_npc_samples_v1.sql`、`037_town_npc_runtime_height.sql`、`039_shared_town_npc_life_v1.sql`、`040_shared_town_npc_conversation_v1.sql`、`041_town_npc_schedule_payload.sql`、`042_town_agent_global_policy.sql`、`043_town_agent_decision_slots.sql`、`044_town_asset_sort_mode.sql`、`045_town_asset_split_occlusion.sql`、`046_town_asset_split_layers.sql`、`047_town_world_events_interest.sql`、`048_town_world_clock_weather.sql`、`049_town_npc_lifecycle_v1.sql`。
- `src/DeskBox.NpcWorker/Program.cs`：Worker 调度、租约、模型回退和服务端动作调用。

客户端和桌面端：

- `src/DeskBox/Services/SharedTownSession.cs`：认证请求、快照、变化、邀请和实时桥接。
- `src/DeskBox/Assets/Tree/TownPrototype/town.js`：Phaser 地图、NPC/玩家渲染、气泡和动态深度。
- `src/DeskBox/Assets/Tree/TownPrototype/town-depth.js`：素材排序模式和深度计算。
- `src/DeskBox/Assets/Tree/TownPrototype/town-residents.js`：居民目录、详情、聊天、邀请和定位状态机。
- `src/DeskBox/Assets/Tree/TownPrototype/town-ui-actions.css`、`town.css`、`index.html`：运行界面覆盖层。

素材自动化：

- `scripts/analyze-town-assets.py`：批量 PNG/图集分析，输出 `needs_review` 和排序建议。
- `scripts/verify-town-asset-analysis.py`、`scripts/verify-town-sort-mode.mjs`、`scripts/verify-town-auto-scan.mjs`：自动化验证。

### 17.3 验证与线上发布基线

本地验证基线：

- `DeskBox.Tests` x64 全量回归：5357/5357 通过。
- 当前共享小镇 PHP、NPC、导航、经济、实时和管理测试均通过；MySQL 专用测试仅在本机没有 DSN 时跳过。
- `verify-town-prototype.mjs`、`verify-town-realtime.mjs`、`verify-town-player-state.mjs`、`verify-town-residents.mjs`、`verify-town-auto-scan.mjs` 通过。
- `town.js`、`town-depth.js`、`town-residents.js`、Web 管理脚本通过语法检查。
- canonical Debug：`D:\work\C#\deskbox\src\DeskBox\bin\Debug\net10.0-windows10.0.22621.0\DeskBox.exe`。

线上发布基线：

- Worker：线上当前为 `deskbox-npc-worker:v40-world-clock`，容器状态 running、重启次数为 0；已验证快照、时钟和行动请求持续返回 HTTP 200，5 个样例 NPC 可运行。`v39-world-clock`、`v38-p2-snapshots` 及更早镜像仍保留用于回滚。
- 双账号已确认：玩家位置/装饰隔离、玩家和 NPC 移动同步、五人同屏、断线重连、对话气泡和连续面对面交谈。
- 真实地图改道已验收：临时阻挡对象放置/删除均已清理，导航版本保持递增。
- 素材自动排序 Web 发布备份：`/root/deskbox-backups/town-asset-sort-20261114-160759/`。
- 自动识别按钮修复备份：`/root/deskbox-backups/town-auto-scan-20261114-1620/`。
- `044_town_asset_sort_mode.sql` 已在线执行，`town_asset_items.sort_mode` 为 `VARCHAR(16) NOT NULL DEFAULT 'y_sort'`；当前统计 `background=8`、`y_sort=39`。
- 自动识别修复后的公网脚本已回读 HTTP 200，远程 SHA-256 与本地一致。
- 房屋拆分发布备份：`/root/deskbox-backups/town-asset-split-20261114-175143/`；`town_asset_items.split_y` 已在线执行为 `SMALLINT`，迁移 SQL、PHP 8.2 语法检查和三份远程 SHA-256 回读均通过。
- 房屋拆分后台保存调用链已补齐并部署；`admin_web.php`、`town_assets.php`、后台视图和 `045_town_asset_split_occlusion.sql` 的远程 SHA-256 与本地一致。
- 自动识别批量草稿发布备份：`/root/deskbox-backups/town-asset-bulk-20261114-180826/`；`town_assets.php`、`admin_web.php`、`api/admin/index.php`、`town-assets.js` 均完成 PHP/本地 JS 检查，远程 SHA-256 与本地一致，后台入口公网 HTTP 返回 200。
- 真实房屋样例已发布到素材版本 14：`town-source-fangwu01`（房屋01）为 `sort_mode=split`、`split_y=96`；发布前状态为 `y_sort / NULL`，可使用发布版本回滚恢复。
- x64 Release 已在独立干净 worktree 按 `Platform=x64`、`RuntimeIdentifier=win-x64`、`SelfContained=false`、`WindowsAppSDKSelfContained=false` 重新生成。随后发布版本 `2026111801` 已创建正式安装器并上传 GitHub Release、FTP/HTTP 镜像；GitHub `main` 的合并提交为 `ea67ddf2a999874d56de16d3b8e7309a58266582`，`update/stable.json` 已核对版本、大小和 SHA-256。

本轮验证（2026-11-14）：

- `dotnet test .\tests\DeskBox.Tests\DeskBox.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64`：5357/5357 通过，0 失败，0 跳过。
- PHP 语法检查及 NPC 生命周期、社交、任务执行、玩家邀请、空间记忆、居民接口测试全部通过。
- `verify-town-auto-scan.mjs`、`verify-town-sort-mode.mjs`、`verify-town-residents.mjs` 全部通过。
- 使用本地真实浏览器加载 `index.html` 做布局回归：1280×720、720×540、640×480 均无页面横/纵向溢出；编辑模式素材栏、地图和居民面板尺寸均在视口内；640×480 漫游模式素材栏按设计收起为 0 宽；居民面板内部无横向或纵向溢出。截图保存在 `output/playwright/town-ui-1600x900-edit.png`、`output/playwright/town-ui-640-edit-resident.png`。
- canonical Debug 已从 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe` 启动；Computer Use 原生管道当前不可用，因此房屋遮挡和窄屏 UI 仍未取得人工画面证据，不能标记为完成。
- 2026-11-15 三段房屋遮挡修复：Phaser 分段不再用完整纹理 `setCrop` 缩放，而是为每个分段生成独立纹理；按后台“上方前景、下方背景”的定义，首段固定在人物前，中段按人物脚底 y-sort，末段固定在人物后；动画图集的分段纹理也会随帧刷新。`node scripts/verify-town-sort-mode.mjs`、`node --check src/DeskBox/Assets/Tree/TownPrototype/town.js` 和 Debug 构建均通过，人工画面验收仍待在当前素材上复测。
- 2026-11-15 三段房屋层级二次修复：统一使用 `splitSegmentDepth` 计算上/中/下三段，明确上段为前景、中段为人物脚底 y-sort、下段为背景；编辑器的前景引用改为首段，避免把下段误当成前景；`index.html` 和 `town-depth.js` 增加房屋拆分模块版本号，避免 WebView2 继续使用旧的两段脚本缓存。`node scripts/verify-town-sort-mode.mjs`、`node --check src/DeskBox/Assets/Tree/TownPrototype/town.js`、`git diff --check` 和 Debug 构建通过；当前 Debug 进程已从 canonical 路径重新启动，真实素材画面仍需用户在新进程中复测。
- 2026-11-15 三段房屋显示稳定性修复：分段纹理键加入源区域签名，素材从两段改为三段时不会复用旧的中段 CanvasTexture；分段图像不再调用无参数 `setCrop()`，避免 Phaser 保留旧裁剪矩形导致中段透明或错位；清理素材目录时改为按前缀删除所有历史分段纹理；模块缓存版本更新为 `house-split3`。`town_asset_sort_mode_test.php`（15 checks）、`verify-town-sort-mode.mjs`、JavaScript 语法检查和 DeskBox Debug 构建均通过；当前 canonical Debug 已重新启动，进程路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。线上 Web/桌面发布和真实素材人工画面验收仍未完成。
- 2026-11-15 三段房屋补充防护：分段变更时复用 Phaser `Image`，代码在绑定分段纹理时显式清除可能遗留的 crop 状态并重设锚点；这是一项防护，尚未证明它是本次真实素材异常的原因。现有排序脚本、PHP 排序测试和 Debug 构建通过，但脚本主要检查代码和深度计算，不能替代真实画面复现。原生画面自动化管道不可用，需用用户的真实三段房屋复测，确定中段及前后遮挡是否正确。
- 2026-11-15 三段房屋再次修正：客户端分段解析同时接受数组、逗号文本和旧缓存中的 JSON 数组文本；分段源高度和显示高度回归断言确保三段连续覆盖整张素材，且明确上段前景、中段脚底排序、下段背景。`town.js`、`town-depth.js` 和入口脚本统一提升模块版本号 `house-split6`，避免 WebView2 复用旧的两段缓存。静态排序、JavaScript 语法、PHP 素材排序测试及 canonical Debug 构建通过；仍需用用户报告的真实三段素材完成画面验收。
- 2026-11-15 线上三段房屋故障定位与修复：检查发现远程 `town_asset_items` 缺少 `split_lines_json`，导致多条拆分线保存时被静默丢弃；已在线执行可重复的 `046_town_asset_split_layers.sql`，确认字段创建且旧 `town-source-fangwu01` 数据从 `split_y=96` 镜像为 `[96]`。同时给 `town_asset_save_item()` 增加迁移缺失时的 503 防护，避免以后再次静默保存错误配置；PHP 文件已备份到 `/www/wwwroot/sltbox.2leaves.cn/api/_app/town_assets.php.bak-20261115-house-split-guard2` 并上传，规范化换行后的远程 SHA-256 与本地一致。三段素材仍需重新保存两条拆分线、发布素材版本后进行真实画面验收。
- 2026-11-15 三段房屋复测防护：`splitDisplaySegments()` 现在同时接受发布目录的 `split_lines`、旧缓存的 JSON 文本、数据库形态的 `split_lines_json` 和编辑草稿的 `splitLines`；回归脚本增加了三段完整覆盖和“上段始终在人物前、下段始终在人物后、中段按脚底排序”的相对深度断言。房屋模块缓存版本提升为 `house-split7`，canonical Debug 已重启并确认运行路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。真实三段素材的人工画面验收仍待用户复测。
- 2026-11-15 三段房屋真实渲染复现与修复：浏览器复现确认旧入口缓存仍加载 `house-split7`，三段 `split_lines` 因旧客户端只识别单条 `split_y` 而回退为整张纹理；同时发现 Phaser `TextureManager.get()` 对不存在的分段键返回 `__MISSING`，以及 `Texture` 没有 `refresh()` 方法，导致新分段纹理无法注册或刷新。入口、深度模块缓存版本统一提升为 `house-split9`；分段纹理改用 `textures.exists()` 后再 `addCanvas()`，通过 `TextureSource.update()` 刷新，动画帧也同步修正。真实浏览器复现已验证 3 个独立 48×48 分段纹理均绑定成功、深度分别为上段前景 `100368`、中段脚底排序 `3416`、下段背景 `-49536`，控制台 0 错误；`node scripts/verify-town-sort-mode.mjs`、JavaScript 语法检查、Debug 构建均通过，canonical Debug 已重启。仍需用户用真实三段房屋素材完成最终画面验收。
- 2026-11-15 高建筑自动分层兜底：线上仍有 `超市01`、`村长家01` 等高建筑保持 `y_sort` 且没有拆分线，整张图片会共享一个深度平面，人物经过中段时就会出现“上段/下段关系反转或中段像消失”的视觉结果。客户端现在对未配置拆分线且高度至少 48 像素的建筑自动生成两个 16 像素对齐的近似三段边界；已有 `split_y`/`split_lines` 优先，手工边界不会被覆盖。模块版本提升为 `house-split10`，静态三段覆盖/深度断言、JavaScript 检查和 Debug 构建通过。自动边界是首屏兜底，真实素材若屋檐边界特殊仍可在后台填写精确拆分线。
- 2026-11-16 `town-source-fangwu01` 三段房屋线上收口：核对素材原图为 `128×176`，将线上草稿和活动发布版本（release `22`）统一为 `sort_mode=split`、`split_lines=[96,144]`。三段分别为上段 `0–96`（固定人物前）、中段 `96–144`（按人物脚底 y-sort）、下段 `144–176`（固定人物后）；PHP 8.2 远程读取已确认活动版本包含两条拆分线。客户端继续使用 `house-split11` 分段纹理和独立深度计算，静态排序脚本、JavaScript 语法检查、x64 测试和 Debug 构建均通过。当前只剩在原生 DeskBox WebView 中让人物从房屋后方、墙体中段和屋檐前方各走一次的人工画面验收，确认中段不再消失且上下遮挡关系符合上述定义。
- 2026-11-16 三段房屋裁剪状态收口：复核 `town.js` 发现分段纹理已是独立完整 Canvas，却仍对 Phaser `Image` 调用了无参数 `setCrop()`；在两段素材切换为三段时，旧裁剪状态可能把中段裁成透明，同时造成上下片段看起来层级反转。现已移除该调用，保留按分段签名重建 Image 的防护；入口模块缓存版本提升为 `house-split12`；新增 `town-source-fangwu01` 的 `128×176`、`split_lines=[96,144]` 三段连续覆盖和前/中/后深度回归断言。仍需在原生 WebView 中完成一次真实画面复测。
- 2026-11-16 原生复测前置修复：核对发现运行中的 DeskBox 进程仍是旧 Debug 二进制（进程启动时间早于 `house-split12` 源码修改），因此实际仍加载旧的两段裁剪逻辑。已停止该进程，重新构建 `src/DeskBox/DeskBox.csproj`（0 错误、9 个既有警告），并从 canonical Debug 路径重新启动 PID 8784；启动后的输出目录已确认包含 `house-split12` 和无 `setCrop()` 绑定逻辑。`town-source-fangwu01` 线上配置复核为 `128×176`、`split_lines=[96,144]`。仍需在新进程中完成从房屋后方、墙体中段和屋檐前方各走一次的人工画面验收。
- 2026-11-16 当前 Debug 实例已再次按仓库流程刷新：停止旧进程后重新构建成功（0 错误、9 个既有警告），并从 canonical 路径启动 PID 11284。房屋入口版本测试通过（`SharedTownEntry_UsesTheCurrentHouseSplitRendererVersion` 1/1），`verify-town-sort-mode.mjs`、两个模块语法检查和 `git diff --check` 通过；当前进程确认使用 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。代码和线上 `town-source-fangwu01` 配置已具备三段独立纹理及前/中/后深度规则，仍需用户在此新实例中完成三条路径的真实画面验收。
- 2026-11-16 三段房屋边界兜底补强：发现明确选择“前后景拆分”但旧发布配置没有带回多条 `split_lines` 时，客户端会退回整张图片的单层 y-sort，从而产生“下段在前、上段在后、中段像消失”的现象。现在所有明确 `sort_mode=split` 的素材（即使分类是 `object/other`）在缺少有效拆分线时也按 16 像素网格自动生成三段边界；房屋子分类也纳入兜底识别。入口和深度模块缓存版本提升为 `20261116-house-split13`，避免 WebView2 复用旧模块。`verify-town-sort-mode.mjs`、PHP 素材排序测试、JavaScript 语法检查和 canonical Debug 构建均通过；当前 canonical Debug 进程为 PID 33920，仍需用户复测三段素材的后方/中段/前方路径。
- 2026-11-16 `town-source-fangwu01` 本地真实图集复现：从线上图集 `1e1d706386458f7ccafba3db473434a2` 裁剪原始 `128×176` 素材，在浏览器中按活动版本 22 的 `split_lines=[96,144]` 加载；三段独立纹理均成功渲染，顶部固定遮挡人物，中段随人物脚底排序，底部固定在人物后方，未复现中段消失或上下反转。随后按仓库流程重新构建并启动 canonical Debug，当前唯一进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`（PID 9300）。`verify-town-sort-mode.mjs`、`town_asset_sort_mode_test.php`（15 checks）和 JS 语法检查通过；仍需用户在该新实例中完成原生 WebView 三条路径的画面验收。
- 2026-11-16 三段房屋问题复核：为避免 WebView2 继续复用上一轮 `house-split13` 模块缓存，入口、C# 小镇窗口和深度模块统一提升为 `20261116-house-split14`；停止旧实例后按仓库流程重建并启动 canonical Debug，当前进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`（PID 22204）。`verify-town-sort-mode.mjs`、`town_asset_sort_mode_test.php`（15 checks）、JavaScript 语法检查和 Debug 构建通过（0 错误，18 个既有警告）。代码仍按 `split_lines=[96,144]` 生成上段前景、中段脚底排序、下段背景；需要在新实例中重新走过三条高度区间，确认用户报告的上下反转是否来自旧缓存。
- 2026-11-17 三段房屋兼容性补强：发现发布目录可能同时带有空的 `split_lines` 公共数组和旧的 `split_lines_json`/`split_y` 字段；旧逻辑会把空数组当成最终值，导致三段素材退回两段或整图排序，表现为中段消失、上下遮挡关系反转。`rawSplitValues()` 现在会跳过空别名并继续读取有值的兼容字段；图集解码后以实际 Canvas 裁剪尺寸同步 `item.width/height`，避免拆分线超出真实帧而采样到透明像素。缓存版本已提升为 `20261117-house-split15`，回归脚本新增空公共数组兼容断言；线上 `town-source-fangwu01` 当前仍为 release 22、`128×176`、`split_lines=[96,144]`。本地代码和静态回归已通过，仍需在新 Debug 实例中完成一次原生 WebView 画面复测。
- 2026-11-17 三段房屋别名与热切换补强：继续发现兼容字段可能同时存在旧的一条 `split_y`、旧公共数组和新 `split_lines_json=[96,144]`；客户端现在合并所有非空拆分别名并去重，优先保留更完整的三段边界，避免中段被旧单线配置吞掉。同时从旧两段渲染切换到三段渲染时主动销毁遗留的整图/前景 Image，避免旧裁剪状态继续覆盖新切片。缓存版本提升为 `20261117-house-split16`；`verify-town-sort-mode.mjs`、PHP 素材排序测试、JavaScript 语法检查、房屋入口测试和 Debug 构建通过。canonical Debug 已重启，当前只剩原生 WebView 中对 `town-source-fangwu01` 后方/中段/前方三条路径的画面验收。
- 2026-11-17 三段房屋旧单线隔离补强：发现兼容字段不能简单全部合并；如果旧 `split_y` 与新的两条 `split_lines` 数值不同，会凭空增加第四段，造成中段错位、上下遮挡关系异常。`rawSplitValues()` 现按候选完整度选择一组拆分线：优先使用两条及以上的新数组，空数组不覆盖有效 JSON，只有没有更完整配置时才使用旧单线。新增“新 `[96,144]` + 旧 `split_y=48` 仍只生成三段”的回归断言；入口缓存版本提升为 `20261117-house-split17`。静态排序脚本、JavaScript 语法检查、PHP 素材排序测试和房屋入口 x64 测试通过；已按仓库流程重建并启动 canonical Debug，当前 PID `26316`。仍需在该新实例中完成原生画面验收。
- 2026-11-17 房屋三段缓存复测：用户再次报告三段房屋出现“下段压在人物前、中段消失、上段在人物后”。用真实 `town-source-fangwu01` 图集（`128×176`，`split_lines=[96,144]`）在浏览器回归中加载三张独立 Canvas 纹理，确认上段 `foreground`、中段 `y_sort`、下段 `background` 均存在，三条路径截图未复现中段缺失或上下反转，控制台无错误。为排除原生 WebView 仍持有旧入口/模块缓存，`index.html`、`town.js`、`TreeTownPrototypeWindow.cs` 和入口契约统一提升为 `20261117-house-split19`；已停止旧进程、重建 `src/DeskBox/DeskBox.csproj`（0 错误、9 个既有警告），并从 canonical Debug 启动唯一 Debug 实例。当前仍缺原生 WebView 的人工画面证据，需用户在新实例中重新走过房屋后方、墙体中段和屋檐前方三条路径。
- 2026-11-17 房屋三段缓存再次隔离：远程活动版本 22 复核仍为 `town-source-fangwu01`、`128×176`、`split_lines=[96,144]`；入口、深度模块和 C# 页面版本提升为 `20261117-house-split20`，避免 WebView2 继续复用旧的整图/两段脚本。停止旧 DeskBox 后重新构建成功（0 错误、9 个既有警告），canonical Debug 已启动且仅有一个进程，入口契约测试、排序脚本、PHP 素材排序测试和 JavaScript 语法检查通过。三段规则保持为上段固定前景、中段按人物脚底排序、下段固定背景；仍需用户在该新实例中完成真实画面复测。
 - 2026-11-17 三段房屋裁剪状态最终修正：发现仅设置完整矩形 `setCrop(0,0,w,h)` 仍会让 Phaser `Image` 保持 cropped 状态；当旧两段/整图对象切换为三段时，旧裁剪状态可能继续造成中段透明或前后段看似反转。现在绑定每个独立分段纹理后调用 `resetCropObject()` 完全清除裁剪组件，入口版本提升为 `20261117-house-split22`。`verify-town-sort-mode.mjs`、PHP 素材排序测试、JavaScript 语法检查和 Debug 构建均通过；canonical Debug 已按流程重新启动，当前唯一进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。仍需用 `town-source-fangwu01` 走过房屋后方、墙体中段和屋檐前方三条路径确认原生 WebView 画面。

### 17.4 明确暂缓范围

以下项目按用户要求暂缓，不应重新加入第一版验收：

- Token 使用量报表、费用换算、价格/币种历史和模型连接测试。
- 玩家经济、玩家钱包、玩家购买和玩家领取。
- 关系衰减、敏感内容分类审核。
- 永久桥梁建造队列。
- 真正 3D 导航和复杂 3D 场景。

### 17.5 当前未完成和下一步

按优先级继续：

1. **房屋前后景拆分收口**：已完成。实现、后台保存、数据库迁移和线上部署均已完成；`town-source-fangwu01` 使用 `split_lines=[96,144]`，三段独立纹理和中段 y-sort 已在原生 Debug WebView 验收：人物从房屋后方、墙体中段和屋檐前方经过时，中段连续可见，上段始终在人物前，下段始终在人物后。
2. **素材批量工作流收口**：自动识别结果批量生成草稿、事务保存和地图连续铺设笔刷已完成；后续可优化自动命名预览以及批量确认/撤销，不再阻塞第一版素材编辑。
3. **真实 UI 视觉回归**：浏览器布局基线已覆盖 1280×720、720×540、640×480，并确认素材栏收起、居民面板和页面边界不溢出；仍需在 DeskBox 原生 WebView 中补一次 16:9、窄窗口和不同素材数量的人工画面确认，尤其是气泡和地图上下文栏。
4. **P1-4 图片对话真实验收**：服务端、Worker、客户端上传链路和自动回归已完成，仍需可用真实登录会话选择图片并确认线上识别结果与对话渲染。
5. **P2-2 社会事务受控验收**：功能开关保持关闭；仍需认证管理员创建一次受控样例，验证候选资格、并发响应、权限和失败回滚后再考虑开启。
6. **发布范围控制**：源码已推送并合并到 GitHub `main`（当前文档提交为 `ff173309`，桌面发布代码提交为 `4e9e2649`）。`v2026111902` 正式安装器、GitHub Release、HTTP 镜像和 `update/stable.json` 均已发布并核对版本、大小和 SHA-256。Web/数据库发布不会自动更新桌面程序。

后续每次修改后仍需记录：修改文件、数据库迁移、测试命令、线上备份目录、远程哈希/HTTP 回读，以及未完成的人工画面验收。

## 18. AI Town P1/P2 能力移植开发方案（可执行版，2026-11-15）

本节承接前面对 `ai_town_2D` 的能力对照，只设计 P1/P2 能力，不改变第一版已经完成的 NPC、导航、经济和面对面交谈边界。所有能力都采用同一条数据流：

```text
PHP/MySQL 权威状态
        ↓
NPC Worker 规划和异步处理
        ↓
变化游标 / WebSocket 事件
        ↓
Phaser 地图与居民面板投影
```

Godot 的 GDScript 只作为行为和数据契约参考，不直接移植 `Node`、场景树或 Godot 主循环。

#### 当前实现状态

| 能力 | 当前状态 | 已落地入口 | 下一道门槛 |
| --- | --- | --- | --- |
| P1-1 公共事件时间线 | 已完成本地和线上垂直切片 | `town_world_events`、`events.php`、事件抽屉、迁移 047 | 任务/交易/天气来源已具备投影入口；仍需用两个认证客户端验收断线后的游标和回执 |
| P1-2 感知和兴趣点 | 已完成服务端和 Worker 线上切片 | `town_interest_points`、放置素材自动兴趣点投影、`perception.php`、Worker `agent_context.observations` | 线上 Worker 快照已恢复 200；仍需用认证账号验证真实墙体/高度差画面；复杂视觉模型仍不做 |
| P1-3 时间和天气 | 已完成本地、线上迁移和 Worker 推进 | `town_world_state`、`clock.php`、`clock-advance.php`、NPC 天气路线戳、迁移 048 | Worker 已按租约读取并推进时钟，天气到期和路线重规划仍需双客户端画面验收 |
| P1-4 图片参与对话 | 客户端接入与服务端垂直切片已完成，真实认证验收待做 | 居民对话框提供图片选择；客户端通过 WebView Bridge 发送 PNG/JPEG/WebP，C# 以 multipart 转发到服务端；服务端执行 4 MB、400 万像素和格式校验，请求期间转发到 Worker `photo-facts`，原图不持久化；识别摘要、NPC 回复和连续对话记录已接入；WebView 消息上限已覆盖 4 MB 原图的 Base64 传输 | 需要在 Debug 登录会话中实际选择图片，验证识别文本、NPC 回复、连续文字交谈和忙碌回复；随后再做线上桌面端发布 |
| P2-1 居民生命周期 | 已完成本地和线上垂直切片 | `049_town_npc_lifecycle_v1.sql`、管理后台状态保存、Worker 到期恢复、行动/任务生命周期守卫、NPC 目标过滤、WebSocket 生命周期增量、客户端隐藏离镇实体并显示恢复状态 | 线上迁移和 Worker 已完成；仍需用认证账号验收离开、受伤、恢复、断线重连；死亡和替补仍不在本阶段 |
| P2-2 社会冲突与调解 | 结构和权威任务闭环已部署，功能开关关闭 | `050/051` 已在线执行；承诺绑定 `town_npc_tasks`，任务完成或失败会回写承诺；Worker 调度代码、嵌套 JSON 兼容、idle rest 接管和 35 项 Worker 测试通过；PHP 文件已备份、上传并完成远程 SHA-256 回读；MySQL 结构与 `FOR UPDATE` 锁测试通过；线上 Worker 健康正常。 | 仍需开启前完成并发/权限和候选动作的线上认证验收；功能开关必须保持关闭直到验收完成。 |
| P2-3 世界快照与恢复 | 已完成一次线上受控恢复；适配器和真实工作结果已验证，完整灾备演练未收口 | `052_town_world_snapshots_v1.sql`、`053_town_world_event_journal_v1.sql`、快照哈希校验、幂等 journal 追加、Worker 专用查询、时间/天气、NPC 休息/行走/失败/工作完成、NPC 交易、`npc_task_replay_delta`（农田、市场、工坊、收入）以及社会事务 opened/response/承诺/结案适配器已完成；恢复接口显式校验版本和 SHA-256；线上受控恢复前已完成数据库备份，源快照 `48ee3eb921bd23af0f610aaa9b04b4d8` 重放 9 条事件，世界版本 `301→302`，新快照 `791db1a73cc03740a22543358dbe2818`，`writes_applied=true`；恢复后农田为 `watered`、浇水次数 8、市场库存 96，并确认农田和市场 `work_run_id`；本地 PHP 测试 59 个文件（需 MySQL 的 3 个按规则跳过）和 Worker 测试 39/39 通过；最新线上 dry-run 已覆盖恢复后增量并返回 `replayable=true`、`errors=[]`、`replay_blockers=[]`、`writes_applied=false` | 已完成一次受控恢复和恢复后只读核对；仍需认证用户读取快照和完整灾备演练；线上 Journal 尚未产生新的社会事务增量，因此社会事务恢复尚未进行生产写入；恢复后 Worker 产生的 `npc_task_completed(action=work)` 已补适配并上线，不能再次盲目恢复 |
| P2-4 地图语义生成 | 已完成并上线 | 素材保存入口规范化并校验 `plain/blocked/stairs/water/bridge`、`ladder/bridge_kit`、楼梯连接方向和高度；自动识别批量草稿根据名称、分类、子分类和标签推断水域、桥梁、楼梯、道路或阻挡物，无法判断时安全回退 `blocked`，结果仍是可编辑草稿；后台提供只读“预览导航影响区块”，按已放置对象汇总区块、对象数、导航类型并生成预览 SHA-256；发布表单携带预览指纹，服务端重新计算并拒绝过期预览；新增 `054_town_asset_release_audit.sql` 和 `town_asset_release_audits`，发布时记录 release、版本、发布人、导航版本和预览指纹；后台新增审计查询表；远程完成 v22→v21→v22 可逆回滚演练并核对配置指纹；相关 PHP/JS 已备份并上传线上，PHP 7.4/8.2 lint、JavaScript 校验、本地 PHP 测试和远程 SHA-256 回读通过 | 首版闭环完成；后续可按需要增加审计筛选和回滚操作的二次确认 |
| P2-5 运行时遥测 | 已完成本地和线上垂直切片 | `029_town_agent_observability.sql`、`town_agent_runs`、`town_npc_activity_logs`、Worker 专用运行记录接口、管理员 Agent 报表；支持模型调用、回退/失败、动作、路线、说话、心情摘要、延迟和 Token 汇总；不保存完整提示词、API Key 或图片内容 | 仍可在真实高并发后增加采样聚合和帧预算图表；费用金额继续按包月约定保持参考性质，不作为线上计费依据 |
| P2-6 触控适配 | 条件项 | 尚未启用 | 明确触屏发行目标后再开工 |

P1-1 至 P1-3 共享同一个 `source_key` 幂等约束和事件游标。P1-3 的客户端只显示服务端返回的 `revision`、`minute_of_day` 和 `weather_code`；浏览器本地时间不推进世界状态。后续 Worker Scheduler 必须携带租约，并使用 `expected_revision`，冲突时重新读取而不是覆盖其他 Worker 的推进。

### 18.1 依赖和实施顺序

P1 能力依赖已有的 NPC 事件、变化游标、导航版本和 Worker 租约；P2 的居民生命周期和社会冲突依赖 P1 的公共事件投影。建议按以下顺序实施：

1. **事件时间线和感知兴趣点**：先统一事实事件、来源和未读状态。
2. **小镇时间/天气**：让事件和感知具备统一的时间来源，并接入 NPC 行为。
3. **照片对话**：在文本对话稳定后增加临时图片转事实能力。
4. **居民生命周期**：先实现离开、暂时缺席和受伤恢复，再考虑死亡和替补。
5. **社会冲突与调解**：使用公共事务状态机，不把冲突写成模型自由文本。
6. **世界快照与事件日志恢复**：在事件模型稳定后建立快照、校验和恢复流程。
7. **地图语义生成工具**：扩展现有素材分析和发布流程。
8. **运行时遥测和帧预算**：在多人、多人同时行动和室内加载出现真实压力后接入。
9. **移动端触控适配**：只有确定 DeskBox 需要触屏发行时才进入实现阶段。

每一阶段都必须保持旧客户端可读取旧快照；新增字段使用默认值，新增事件类型不能阻塞旧客户端的地图和 NPC 渲染。

### 18.2 P1-1：小镇公共事件时间线

#### 目标

把公告、天气变化、工作完成、交易、动物出现、居民进入建筑和对话结果统一投影为可查询的公共事实。个人记忆仍然按 NPC 单独保存，公共时间线不直接暴露私密记忆。

#### 深模块接口

```text
append_world_event(event) → event_id, sequence
query_world_events(cursor, filters, limit) → events, next_cursor
acknowledge_world_events(actor_id, event_ids, state) → accepted_sequence
```

接口只接受已经由服务器验证的事实事件。去重键使用 `source_type + source_id + source_revision`，同一事件重复提交必须幂等。

#### 数据和服务端

建议增加：

```text
town_world_events
  event_id, world_id, sequence, event_type, source_id,
  actor_npc_id, target_npc_id, public_payload_json,
  visibility, occurred_at, expires_at, created_at

town_world_event_receipts
  event_id, viewer_id, state, read_at, updated_at
```

`town_npc_events` 继续用于 Agent 私有事件；公共事件写入 `town_world_events`，再按可见性投影给 NPC 和登录用户。变化游标和 WebSocket 都只发送事件摘要，详情通过分页查询取得。

#### 前端

- 在居民面板旁增加“事件”抽屉，不覆盖地图操作。
- 支持全部、居民、地点、天气、工作、交易、对话筛选。
- 未读事件显示数量；点开后只更新当前账号的 receipt。
- 事件点击可以定位居民、地点或事件发生的地图格子。

#### 验收

- 同一事件重复写入不会出现两条时间线记录。
- 断线重连后按 `sequence` 补齐，不能漏事件或倒序。
- 私人记忆、模型提示词和 API Key 不进入公共事件 payload。
- 旧客户端忽略未知事件类型后仍可正常移动和渲染。

### 18.3 P1-2：感知和兴趣点系统

#### 目标

让 NPC 通过服务器定义的可见、可听、接近和事件来源感知小镇，而不是每次直接读取全部素材名称。

#### 数据模型

在素材和地点语义之上增加兴趣点：

```text
town_interest_points
  interest_id, world_id, kind, asset_id, object_id,
  tile_x, tile_y, radius, perception_channels_json,
  activity_type, public_name, enabled, updated_at
```

感知结果不保存完整地图副本，只保存短期事实：

```text
perception
  interest_id, observed_kind, source_event_id,
  distance, channel, observed_at, navigation_version
```

#### 深模块接口

```text
inspect_area(actor_id, center, radius, channels) → observations
emit_interest_event(source, payload) → event_id
project_agent_observation(npc_id) → bounded_observation_context
```

服务器检查距离、遮挡、导航版本和权限；Worker 只收到裁剪后的观察上下文。第一阶段只做 `visible`、`audible`、`nearby` 三种渠道，不实现复杂视觉模型。

#### 与现有系统的连接

- 复用 `town_asset_items` 的分类、占地、碰撞和导航语义。
- 复用 `town_npc_memories`，增加 `source_event_id`、`channel` 和 `expires_at`。
- 地图对象移动或删除时，使对应兴趣点和相关空间记忆失效。

#### 验收

- NPC 只能看到半径和渠道允许的兴趣点。
- 墙体、遮挡和高度差不会被错误报告为可见。
- 同一兴趣事件在短时间内会合并，不会重复唤醒模型。
- 旧坐标和旧导航版本不会继续作为可靠目标。

### 18.4 P1-3：小镇时间和天气参与行为

#### 权威状态

增加小镇级状态：

```text
town_world_state
  world_id, absolute_minute, calendar_day, weather_code,
  weather_started_at, next_weather_change_at, revision, updated_at
```

客户端时钟只用于动画插值，不能推进权威时间。Worker Scheduler 按固定间隔推进时间，并生成 `time_advanced`、`weather_changed`、`place_opened`、`place_closed` 事件。

#### 行为规则

- 天气影响 NPC 是否出门、是否避雨、是否改变工作地点。
- 时间影响日程、商店营业、服务点排队和居民睡眠。
- 建筑内部使用室内天气隔离规则，但仍继承小镇时间。
- 没有模型时由确定性规则完成避雨、回家、休息和重新排程。

#### 接口

```text
get_world_clock() → time, weather, revision
advance_world_clock(expected_revision) → new_state, events
set_weather(admin_id, weather_code, duration) → new_state, event_id
```

管理员改变天气必须写入审计日志，不能由浏览器直接修改状态。现有桌面天气组件只作为显示适配器，不直接成为小镇天气来源。

第一版接口落地为：

```text
GET  /api/v2/town/clock.php
POST /api/v2/town/clock-advance.php
```

`clock.php` 面向已开放账号只读；`clock-advance.php` 只接受带有效 Worker 租约的推进请求，并要求 `expected_revision`。天气设置先走同一服务层，管理入口接入审计后再开放，不允许浏览器直接提交天气。

#### 验收

- 两个客户端看到相同的时间、天气和事件序列。
- 天气切换不会让 NPC 继续执行已失效的路线或服务。
- Worker 停止时地图仍可查看，恢复后按当前 revision 补处理事件。
- 手动改变天气和自然天气变化使用同一套事件格式。

### 18.5 P1-4：照片参与对话

#### 处理流程

```text
玩家选择图片
    ↓
WebView 限制格式、尺寸和像素数
    ↓
Worker/模型网关临时转换为文字事实
    ↓
PHP 保存文字、摘要和来源事件
    ↓
原始图片立即释放，不进入存档和公共事件
```

#### 接口和限制

```text
POST /api/v2/town/npcs/conversations/photo
  conversation_id, target_npc_id, image_bytes
→ extracted_text, fact_summary, message_id
```

- 只允许认证用户向当前可交谈 NPC 发送。
- 限制 MIME 类型、文件大小、像素数和处理时长。
- 失败时返回可读错误，不创建空白记忆。
- NPC 对话和记忆只保存转换后的文字、摘要、时间和来源，不保存原图。
- 不新增“模型连接测试”，复用现有 Worker 供应商配置和错误记录。

#### 验收

- 原图不会落盘、进入日志、存档、公共事件或数据库 JSON。
- 图片转换失败不会影响连续对话状态。
- 对话历史可显示“图片事实摘要”，但不会暴露内部提示词。
- 两个客户端只同步文本摘要和消息状态。

#### 当前实现状态（2026-11-19）

- 桌面居民对话框已提供“图片”按钮和隐藏文件选择器，客户端限制 PNG/JPEG/WebP，单图不超过 4 MiB。
- 图片通过 WebView Bridge 发送到 C#，C# 解码后使用 multipart 调用认证图片对话接口；原图只存在于本次请求内。
- WebView 入站消息上限为 8 MiB，覆盖 4 MiB 原图 Base64 后的体积；服务端仍执行 4 MiB 原图、400 万像素和 MIME 校验。
- 识别出的事实摘要和 NPC 回复会进入当前连续对话气泡、聊天窗口和对话记录；图片字节不进入日志、事件或记忆。
- 本地 Debug 构建和 `SharedTownSessionTests` 20/20 已通过，新增完整 4 MiB multipart 上传、非法图片输入回归；前端 `verify-town-residents.mjs` 已覆盖图片格式、空文件和 4 MiB 边界；服务端 PHP lint、匿名会话拒绝和 Worker 39/39 测试已通过。
- 尚缺真实认证桌面会话验收：选择图片、确认识别文本和 NPC 回复、连续发送文字，以及 NPC 忙碌时返回“我现在正在忙，请先等一等”。完成后才发布桌面端版本。

### 18.6 P2-1：居民生命周期和替补

#### 状态机

```text
active → absent → returning → active
active → injured → recovering → active
active → retired → replacement_pending → active
active → dead                       （后续阶段）
```

第一阶段只实现 `absent` 和 `injured`，不引入不可逆死亡。所有状态变化必须有来源事件、开始时间、预计恢复时间和操作者。

#### 数据模型

```text
town_npc_lifecycle
  npc_id, state, reason_code, source_event_id,
  started_at, expected_return_at, replacement_npc_id,
  revision, updated_at
```

关系、记忆、住房和库存继续归属原 `npc_id`。替补居民使用新的身份，不覆盖原身份；需要显式记录 `replacement_npc_id` 和公开展示名称。

#### 运行规则

- `absent/injured` 居民不接收新的普通工作任务，但保留已有任务的取消或结算路径。
- 服务点根据在岗居民重新计算容量。
- 地图上显示离镇、休养或替补状态，不渲染不存在的实体。
- 恢复、替补和停用必须通过服务端事务完成，并发布公共事件。

#### 验收

- 恢复和重连后生命周期状态不回退。
- 旧居民关系、记忆和交易记录不被替补居民覆盖。
- 替补居民可继续使用原有职业/住房配置，但权限和库存重新校验。
- 模型不可自行宣布死亡、替补或修改身份。

### 18.7 P2-2：社会冲突与调解

冲突作为一种受限的社会事务，不做战斗系统。建议沿用“创建—响应—执行—结案”的状态机：

```text
open → collecting → assigned → executing → closed
                    ├──────────────→ cancelled
                    └──────────────→ failed
```

建议数据：

```text
town_social_matters
  matter_id, kind, source_event_id, creator_id,
  state, public_summary, deadline_at, revision, created_at

town_social_responses
  response_id, matter_id, resident_id, option_id,
  response_kind, public_text, created_at

town_social_commitments
  commitment_id, matter_id, owner_npc_id, action_id,
  conditions_json, state, due_at, completed_at
```

服务器负责候选人资格、响应幂等、承诺数量、截止时间和结案条件；模型只能在允许的选项中选择。前端先复用事件抽屉和居民面板，显示“待响应、处理中、已完成、失败原因”，不新增复杂弹窗。

验收重点是：重复响应不重复结算；承诺失败有明确原因；一项冲突不会绕过库存、路线、职业和权限校验；公共摘要不泄露私人记忆。

### 18.8 P2-3：世界快照和事件日志恢复

#### 保存结构

```text
town_world_snapshots
  snapshot_id, world_id, base_sequence, schema_version,
  state_json, sha256, created_at

town_world_event_journal
  sequence, world_id, event_type, payload_json,
  schema_version, idempotency_key, created_at
```

快照保存地图对象、NPC 运行状态、任务、时间天气、经济、生命周期和社会事务；事件日志保存快照之后的增量。公共时间线和 Agent 私有事件都必须可由事件序列重建。

#### 恢复流程

1. 校验快照哈希和 schema 版本。
2. 读取最近完整快照。
3. 按 sequence 重放增量事件。
4. 检查 NPC、对象、库存和路线引用完整性。
5. 生成恢复报告；证据不足时只导出诊断，不猜测修复。
6. 恢复完成后发布新的快照 revision，旧快照保留只读。

恢复接口只允许受控管理流程调用，普通客户端继续使用当前 `world.php`、`changes.php` 和实时接口。迁移脚本必须支持旧版本快照的显式升级，不在读取请求中隐式修改数据库。

### 18.9 P2-4：地图语义生成和发布工具

在已有透明网格、占地、碰撞、排序模式和房屋拆分基础上，增加统一的素材语义草稿：

```json
{
  "asset_id": "house-01",
  "navigation": {},
  "interaction": {},
  "occlusion": {},
  "camera": {},
  "lighting": {},
  "audio": {},
  "effects": {},
  "review_status": "draft"
}
```

流程固定为：

```text
扫描/导入 → 生成草稿 → 管理员预览 → 校验 → 版本发布 → 客户端按版本加载
```

自动识别只能生成候选值，不能直接修改在线发布版本。校验器至少检查占地越界、交互点落在阻挡区、遮挡切线无效、导航类型冲突和资源文件缺失。发布版本必须可回滚，并写入素材版本和发布人审计记录。

### 18.10 P2-5：运行时遥测和帧预算

#### 服务端指标

- Agent 决策等待、模型耗时、任务执行耗时和失败率。
- 事件队列长度、最旧未处理事件年龄和重复事件数量。
- 导航重规划次数、路线冲突次数和交互点等待时间。
- 每个 NPC 的当前任务、连续失败次数和确定性回退次数。

#### 客户端指标

- Phaser 帧耗时、可见对象数、NPC 插值数量和气泡数量。
- 区块加载/卸载耗时、图片加载失败数和 WebSocket 重连次数。
- 室内房间准备、遮挡切换和地图重绘耗时。

指标采用采样和聚合，默认不记录对话原文、API Key 或图片内容。提供仅管理员可见的诊断接口和本地调试面板；线上公开接口只返回健康状态和粗粒度计数。

#### 帧预算策略

- 地图渲染、NPC 更新、气泡布局和素材加载分别设预算。
- 超出预算时降低远处 NPC 的表现频率，不能降低服务端状态更新频率。
- 区块和室内加载使用队列，不能在一次帧内同步加载全部素材。

### 18.11 P2-6：移动端触控适配（条件项）

只有确定发行目标包含触屏设备时实施。先建立输入适配器：

```text
PointerInputAdapter
  tap, drag, long_press, pinch, cancel
```

Phaser 地图、居民面板和素材编辑器只消费统一输入事件，不直接判断鼠标或触摸。布局增加窄屏、横竖屏、安全区和虚拟摇杆模式；桌面鼠标操作必须保持不变。触控验收覆盖双指镜头、滚动与点击区分、系统中断后的按压状态清理和输入框键盘行为。

### 18.12 统一验收和回滚要求

每个能力完成前必须同时通过：

- PHP 单元/集成测试：权限、幂等、事务、版本冲突和旧数据兼容。
- Worker 测试：事件排序、重试、模型失败回退和租约丢失。
- 浏览器验证：快照、增量、断线重连、事件筛选、定位和窄窗口布局。
- 数据库验证：迁移可重复执行，回滚或旧版本读取不会破坏现有 NPC、地图和玩家装饰。
- 线上验证：备份、迁移结果、远程文件哈希、HTTP 回读和真实账号权限。

部署顺序保持“数据库兼容迁移 → PHP 读取兼容 → Worker 发布 → 前端发布 → 开启新能力开关”。新能力必须有独立 feature flag；发生异常时关闭开关即可回到现有 NPC、导航、经济和对话闭环。

### 18.13 分阶段开发任务和完成定义

#### 阶段 A：公共事实基础（已完成第一版）

完成事件表、兴趣点表、世界时钟表及其读取/分页/回执/感知/推进接口；完成 `SharedTownSession` 桥接和 Phaser 事件抽屉、时钟天气状态显示。阶段 A 的完成证据是 PHP 单元测试、Node 语法检查、x64 C# 会话测试和旧客户端仍能读取 `world.php`。

#### 阶段 B：P1 行为闭环

1. Scheduler 每个租约周期读取时钟，推进固定分钟数；同一 revision 只能成功一次。
2. 时间或天气变化后，取消受影响的 NPC 路线和服务预约，写入重新规划原因。
3. 感知查询增加透明网格、占地高度和导航版本检查；重复事件在短窗口内合并后才唤醒 Worker。
4. 图片对话只把受限的文字事实交给现有会话执行器，原图在请求生命周期结束时释放。

阶段 B 的完成定义是：同一世界的两个客户端显示相同的时钟/天气；天气变化能在事件抽屉中看到并触发 NPC 重新规划；图片失败不会破坏连续对话；没有私密内容进入公共事件。

#### 阶段 C：P2 居民和社会事务

先实现 `active/absent/injured/recovering/returning`，每次状态变更都以事务同时更新生命周期和公共事件。社会事务只能引用服务端提供的候选人、选项和承诺条件；Worker 负责排序和解释，PHP 负责资格、库存、路线、截止时间和结案。

阶段 C 的完成定义是：居民离开、受伤和恢复可重放；已有关系、记忆、住房和库存不被替补身份覆盖；重复响应、超时和失败都有幂等结果。

#### 阶段 D：可恢复世界和工具链

冻结事件 schema 后增加完整快照、增量重放、校验和恢复报告；再接入地图语义草稿、预览、校验、发布和回滚。任何自动识别结果只能停留在草稿，不得直接改变在线对象。

阶段 D 的完成定义是：从最近快照加事件日志可重建同一 `world_revision`；损坏快照只生成诊断报告；素材发布可定位版本、发布人和回滚点。

#### 阶段 E：性能和设备适配

当多人同时行动、室内加载或远距离 NPC 造成可观测压力后，接入服务端/客户端采样指标和帧预算；只有确认触屏发行目标后才实现统一指针适配器。指标默认采样聚合，不记录对话原文、密钥和图片内容。

#### 当前会话续做记录（2026-11-15）

- 修复 P1-1 事件时间线的接口缺口：客户端请求的 `/api/v2/town/events.php` 已补齐，继续复用认证、共享小镇权限、游标、筛选和个人 receipt 投影；原有 `/events/receipt.php` 保持不变。
- 修复 P1-3 首次部署的时钟初始化缺口：`048_town_world_clock_weather.sql` 现在会为已有 `town_worlds` 幂等创建 `clear / day 1 / revision 1` 状态，避免 Worker 取得租约后推进时收到 `town_clock_unavailable`。
- 本地证据：`town_world_events_test.php` 8 项、`town_world_clock_test.php` 7 项通过；新增接口 PHP 语法检查通过。
- 继续实现 P1-2 感知校验：`town_navigation_visibility_between` 使用服务端导航网格检查中间阻挡物和高度差；`town_interest_points_nearby` 支持 visible/audible/nearby 分渠道过滤、导航版本和放置素材自动兴趣点投影；`town_npc_agent_context` 将附近观察投影给 Worker；桌面桥接转发观察高度。
- P1-2 本地证据：`town_world_events_test.php` 12 项通过，覆盖放置素材命名投影、墙体视觉遮挡、遮挡后的 audible 保留和高度差过滤；`town_npc_agent_context_test.php`、`town_npc_spatial_memory_test.php`、`town_npc_social_test.php` 通过；相关 PHP 语法检查通过；Debug 构建 0 错误。
- P1-3 行为闭环补齐：`town_world_clock_advance` 会检测 `next_weather_change_at`，到期恢复 `clear` 并写入 `weather_changed(duration_expired)`；NPC 任务保存 `weather_code/weather_revision`，`town_npc_advance_task` 在天气变化后重新规划路线、重算通行道具并记录 `route_replanned(reason=weather_changed)`；交易/交谈的相邻交互点也支持重新规划。`town_world_clock_test.php` 10 项、`town_npc_task_execution_test.php` 22 项通过，覆盖天气到期和活动任务重新规划。
- 线上数据库和 PHP 文件尚未在本次续做中发布；发布前仍需执行迁移、备份、远程哈希回读和认证账号验收。
- 本次续做完成 P2-1 本地闭环：非 `active` NPC 的新行动返回 `npc_lifecycle_inactive`；已有运行任务在 `absent/injured/returning/recovering` 阶段被安全取消，释放工作点并退回已消耗的梯子/桥梁道具，清空 runtime 任务指针；NPC-NPC 对话和交易目标排除非 `active` 居民。
- Worker 新增 `POST /api/v2/town/agent/lifecycle.php` 的租约内恢复调用，每 10 秒检查到期状态；快照和调度只处理 `lifecycle.state=active` 的居民。恢复增量携带 `npc_id`、生命周期状态、权威坐标和 runtime 状态，经 WebSocket 广播到所有客户端。
- 客户端合并 `npc.lifecycle` 增量；`absent` 不渲染，`injured/recovering/returning` 保留实体并显示“休养/恢复中/返回中”状态。
- 本地证据：`town_npc_life_test.php` 28 项、相关 PHP 语法检查、`DeskBox.NpcWorker.Tests` 25 项、Worker Debug 构建 0 错误；尚未取得线上迁移和双客户端生命周期画面证据。

#### 当前会话续做记录（2026-11-16）

- 重新核对方案后发现上一会话的 P1/P2 代码只完成本地垂直切片，远程数据库没有 `town_world_events`、`town_interest_points`、`town_world_state`、`town_npc_lifecycle` 四张表。另发现迁移 047/048 的 `world_id VARCHAR(64)` 与既有 `town_worlds.world_id VARCHAR(32)` 外键定义不一致；已修正为 `VARCHAR(32)`，新增 `town_p1p2_migration_shape_test.php` 防止回归。
- 远程数据库已备份到 `/root/deskbox-backups/shared-town-p1p2-20261116-075413/`，迁移 047、048、049 已用 PHP 8.2 执行成功；远程确认四张表均已创建。数据库备份使用 `mysqldump`，文件为 `database.sql`，SHA-256 为 `222ca478b5b19b9707499f0c1722e3259bd6ecc9c2b48e5ca4d4979af9241ade`。
- 线上首次启动 Worker 暴露了三个真实兼容错误：漏发布 `town_navigation.php` 导致 `town_navigation_visibility_between()` 未定义；`api/v2/town/agent/lifecycle.php` 的 `dirname` 多一层导致加载站点根目录错误路径；`world.php` 普通 `require` 与 `town_npcs.php` 的 `require_once` 重复声明时钟函数。已分别补齐导航文件、将生命周期入口改为 `dirname(__DIR__, 3)`、将 P1 路由依赖统一改为 `require_once`。
- PHP 线上文件已备份到 `/root/deskbox-backups/shared-town-p1p2-web-20261116-083730/` 并重新发布，远程哈希与本地暂存文件一致；PHP-FPM 8.2 已 reload。修复后 Worker 连续收到 `npcs/snapshot.php` HTTP 200，`clock.php` HTTP 200，`agent/lifecycle.php` HTTP 200，错误日志在 08:37:30 后没有新增上述三类错误。
- Worker 已构建并切换到 `deskbox-npc-worker:v33-p1p2`，备份目录为 `/root/deskbox-backups/worker-p1p2-20261116-081143/`；健康接口正常，Worker 正常读取 NPC 快照、推进时钟、执行生命周期恢复调用。模型超时仍按方案回退确定性日程，不阻塞小镇。
- 本地验证：P1/P2 PHP 测试、迁移结构测试、PHP 语法检查、Worker 测试 25 项、DeskBox.Tests x64 全量 5357 项通过；canonical Debug 已按仓库流程重建并从 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe` 重启。剩余门槛是两个真实认证客户端对公共事件游标/回执、天气同步、感知画面和生命周期离开/恢复/断线重连的人工验收。
- 2026-11-16 P1/P2 线上错误收口：Worker 日志暴露 `NPC worker loop failed: '<' is an invalid start of a value`。直接请求确认 `clock-advance.php` 因入口使用 `dirname(__DIR__, 3)`，解析到站点根目录 `_app/bootstrap.php`，PHP-FPM 返回 200 的 HTML 致命错误页；已改为与同目录 `clock.php` 一致的 `dirname(__DIR__, 2)`，并新增时钟测试断言防止直接 town 路由再次使用三级目录。远程备份为 `/root/deskbox-backups/p1p2-clock-advance-route-20261116-1025/`，本地与远程 SHA-256 均为 `7b4daf227c131f5ed580bb12286da2e4f5c48149429a6b4555ba30b45c356602`；远程 PHP 8.2 lint 和实际 `application/json` 响应均通过。
- 同轮修复 Worker 服务令牌边界：线上 `/opt/deskbox-npc-worker/.env` 的 `NPC_WORKER_TOKEN` 原有首尾空格，Worker 客户端现在在读取令牌和 Worker ID 时统一 `Trim()`；环境文件已清理为 64 位十六进制令牌。Worker 源码已备份到 `/root/deskbox-backups/worker-p1p2-clock-fix-20261116-1045/`，构建并切换到 `deskbox-npc-worker:v34-p1p2-clock-fix`（镜像摘要 `sha256:5383417a0bb13e72d3a57e2b1a7b130ff2c729d5400511a3b678487d7af9b5d7`）。健康接口返回 `ok=true`，重启后连续约 5 分钟的 `clock-advance.php`、NPC 快照和行动请求均为 200，未再出现 `<` JSON 解析错误、401/403/404/500 或 Worker loop failure；`town_world_clock_test.php` 13 项、`DeskBox.NpcWorker.Tests` 25 项通过。
- 2026-11-16 当前会话继续核验：`town_world_events_test.php` 12 项、`town_world_clock_test.php` 13 项、`town_npc_life_test.php` 28 项、`town_p1p2_migration_shape_test.php` 3 项、感知/空间记忆/社会/任务测试均通过；Worker 测试由 25 项增加为 26 项并通过。为定位线上行动接口偶发 400，`TownApiClient` 现在在非 2xx 响应中记录请求路径、状态码和截断后的服务端 JSON（不记录令牌）；新增 `TownApiClientDiagnosticsTests.ActionFailureIncludesBoundedServerErrorBody` 锁定该行为。
- 该诊断改动已构建并发布到线上 `deskbox-npc-worker:v35-p1p2-diagnostics`；旧容器保留为 `deskbox-npc-worker-v34-p1p2-clock-fix-rollback`，备份目录为 `/root/deskbox-backups/worker-p1p2-diagnostics-20261116-111705/`。健康接口返回 `ok=true`，容器 `restart_count=0`；发布后约 8 分钟未出现 Worker loop failure、JSON 解析错误、401/403/404/500 或带响应体的 400/409 行动失败。409 `work_point_busy` 仍按资源冲突语义由重试退避处理，下一步可根据新诊断信息决定是否调整工作点调度。
- 同轮完整回归：32 个 `town_*_test.php` 文件执行完成，失败 0；其中两个需要本地 MySQL DSN 的测试按既有规则跳过；`DeskBox.NpcWorker.Tests` 26 项通过。当前剩余证据不再是本地代码错误，而是两个真实认证客户端对事件游标/回执、时钟天气、感知画面、生命周期离开/恢复/重连和 Worker WebSocket 同屏的人工验收。

#### P2-2 本地开发记录（2026-11-16）

- 重新阅读第 18 节后开始社会事务切片：新增迁移 `050_town_social_matters_v1.sql`、事务/响应/承诺 PHP 原型、服务身份接口及普通账号只读列表；事件抽屉增加社会事务类别名称。本地新增测试覆盖候选资格、重复响应、承诺名额、到期和公共事件投影。承诺重试先检查幂等键再检查名额，并对同一事务的响应/分配加事务行锁。
- 当前实现**不是完整闭环，也不得上线启用**：承诺的动作、条件和完成状态尚未绑定实际 `town_npc_tasks` 的服务端校验与执行结果；Worker 尚未调度社会事务；并发/权限、隐私投影、失败/取消与 MySQL 实际迁移仍需补强。原型接口在上述问题解决前不得作为生产授权接口。
- 本地证据：33 个 `town_*` PHP 测试完成，2 个 MySQL DSN 测试按既有规则跳过；Worker 测试 27 项通过；桌面入口版本测试 1 项通过；canonical Debug 构建 0 错误并重启。原生窗口确认可显示小镇与现有事件抽屉，但尚无社会事务的端到端画面证据。
- 线上 Worker `v35-p1p2-diagnostics` 健康，核验时容器重启次数 0，最近 15 分钟未发现 Worker loop failure 或 4xx/5xx 错误。**当时** P2-2 文件仅上传到服务器临时目录，发布脚本因远程命令转义错误在运行前失败，尚未执行迁移或安装 Web 文件；后续状态见下方 2026-11-17 修复与线上结构发布记录。

#### P2-2 修复与线上结构发布记录（2026-11-17）

- 先补出一个能复现线上风险的回归：社会事务候选项绑定 `walk` 行动时，NPC 在最后一步完成任务的专用分支原先没有调用社会承诺同步函数，承诺会永久停留在 `executing`。`town_npcs.php` 已在该分支加入 `town_npc_sync_social_task_if_available(..., 'completed', ...)`；`town_social_matters_test.php` 新增步行到达后承诺完成并关闭事务的断言，先失败后通过。
- 普通账号的社会事务公开投影现在清除选项 `conditions`，不再暴露库存要求、记忆坐标或其他执行器内部参数；同一测试新增隐私断言。普通账号仍只能通过 `api/v2/town/social/matters.php` 读取公共摘要，Worker 专用写接口继续要求服务身份和租约。
- 本地证据：`town_social_matters_test.php`、`town_npc_task_execution_test.php` 通过；新增 `town_social_mysql_test.php` 在远程 MySQL 8.0 上通过，验证三张表、`task_id CHAR(32)`、任务索引和两连接 `FOR UPDATE` 行锁；Worker 测试由 27 项增至 31 项并通过；Worker 项目构建 0 错误。
- 线上数据库已先备份到 `/root/deskbox-backups/shared-town-social-20261116-190701/`，并执行可重放的 `050_town_social_matters_v1.sql`、`051_town_social_commitment_tasks.sql`。回读确认三张社会事务表、`task_id` 和 `ix_town_social_commitments_task` 存在；迁移前后备份文件 SHA-256 已记录在服务器目录。
- 线上 PHP 8.2 文件已备份到 `/root/deskbox-backups/shared-town-social-web-fix-20261116-191706/` 并上传：`town_npcs.php`、`town_social_matters.php`、Agent 社会事务入口和普通账号列表入口。五个远程 SHA-256 与本地一致；未重启 Worker，`deskbox-npc-worker:v35-p1p2-diagnostics` 继续运行，社会事务环境开关未启用。
- Worker 已构建并切换为 `deskbox-npc-worker:v36-p1p2-social-ready`；旧容器保留为 `deskbox-npc-worker-v35-p1p2-diagnostics-rollback`，切换备份为 `/root/deskbox-backups/worker-p1p2-social-ready-20261116-192355/`。新容器健康接口返回 `ok=true`，重启次数为 0，约 20 秒内快照、时钟和 NPC 行动请求均为 HTTP 200；环境中没有启用社会事务开关，因此线上仍不会自动创建或分配社会事务。
- 当前仍不能宣称 P2-2 完成：需要在开关保持关闭的情况下先由管理员创建受控样例事务，验证服务身份、候选资格、库存/路线/截止时间、双客户端公共事件和失败回滚，最后才允许开启功能开关。

#### 三段房屋遮挡修复记录（2026-11-17）

- 2026-11-17 原生复现进一步定位：用真实 `town-source-fangwu01`（`128×176`、`split_lines=[96,144]`）通过本地浏览器回放读取渲染回执，确认三张独立 Canvas 纹理都已创建，位置连续，深度分别为上段前景、中段脚底排序、下段背景；但三张 Phaser `Image` 的 `isCropped` 仍为 `true`。原因是 `resetCropObject()` 只是返回一个新的裁剪对象，并不会清除 `Image.isCropped` 标志，旧两段/整图对象复用时仍会沿用旧 UV 裁剪，正好解释了“三段中段消失、上下遮挡反转”。现已在绑定每张独立切片纹理后调用无参 `image.setCrop()`，由 Phaser 官方接口清除裁剪状态；诊断回执改为读取真实 `isCropped`。静态排序脚本和浏览器回放均确认三段 `cropped=false`。入口缓存版本提升为 `20261117-house-split23`，待 Debug 重建后进行原生 WebView 三条路径画面验收。

- 用户复测 `town-source-fangwu01`（原图裁剪 `128×176`、拆分线 `[96,144]`）仍出现“下段压在人物前、中段消失、上段在人物后”。运行日志确认图集实际加载为 `x=0,y=0,width=128,height=176`，因此素材尺寸和线上拆分配置没有丢失。
- 客户端分段纹理切换时增加显式 crop 清理：`setTexture()` 后按当前 Canvas 分段的 `frame.realWidth/realHeight` 调用带完整矩形参数的 `setCrop(0,0,w,h)`。这样可以清除 WebView/Phaser 复用的旧两段或整图 `Image` crop 状态；继续禁止无参数 `setCrop()`，避免恢复旧裁剪。现有签名变化销毁重建、分段 Canvas 独立纹理和前/中/后深度规则保持不变。
- 缓存版本统一提升为 `20261117-house-split21`（入口 HTML、`town.js`、深度模块和 C# WebView 入口），避免旧模块继续命中。`verify-town-sort-mode.mjs` 新增完整分段 crop 断言。
- 本地证据：排序脚本通过；PHP 素材排序测试 15 项通过；两个房屋模块语法检查通过；`git diff --check` 通过；`DeskBox.csproj` Debug 构建 0 错误（18 个既有警告）。已停止旧进程并从 canonical Debug 路径启动 PID 13476：`src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。
- 仍需在该新实例中人工确认三条路径：人物位于房屋后方、经过中段墙体、走到屋檐前方；验收标准是上段始终遮挡人物，中段按人物脚底前后变化，下段始终位于人物后方，且中段连续可见。
- 2026-11-17 用户确认复测素材为 `town-source-fangwu01`。按仓库流程再次停止并构建 Debug（0 错误，保留既有警告），从 canonical 路径启动当前唯一实例 PID 34908：`src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。入口和输出目录均确认加载 `20261117-house-split21`；`town-source-fangwu01` 的回归断言仍为 `128×176`、`split_lines=[96,144]`，三段连续覆盖且分别使用前景/脚底排序/背景深度。排序脚本、PHP 素材排序测试（15 项）和两个 JS 语法检查通过。原生 WebView 画面仍需用户在该新进程中完成三条路径验收。

- 2026-11-17 当前工作区运行时复测：用真实图集 `fangwu01.png` 和线上同形态配置 `town-source-fangwu01`（`128×176`、`split_lines=[96,144]`）通过 Playwright 加载当前 `house-split24` 入口。三张独立 Canvas 纹理均成功创建并可见，尺寸为 `128×96`、`128×48`、`128×32`，位置连续为 `y=464/512/544`，裁剪状态均为 `cropped=false`；深度分别为 `100464`（上段前景）、`3512`（中段脚底排序）、`-49456`（下段背景），控制台无错误。`node scripts/verify-town-sort-mode.mjs` 和 `town_asset_sort_mode_test.php`（15 项）再次通过；当前唯一 Debug 进程确认来自 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。代码回放已排除分段纹理、拆分线和裁剪状态问题，剩余证据仍是原生 WebView 中人物从房屋后方、墙体中段、屋檐前方各经过一次的人工画面验收。
- 2026-11-17 三段房屋修复完成本地交付：分段纹理键加入源区域签名，旧两段/整图对象在切换为三段时主动销毁；每个独立切片绑定后清除 Phaser 残留裁剪状态，并保留上段前景、中段脚底 y-sort、下段背景的深度规则。入口、深度模块和 C# WebView 入口统一提升为 `20261117-house-split25`。`node scripts/verify-town-sort-mode.mjs`、`town_asset_sort_mode_test.php`（15 项）、两个房屋模块语法检查、DeskBox.Tests x64（485 项）和 Debug 构建均通过；当前唯一进程为 canonical Debug `DeskBox.exe`（PID 37140）。远程素材配置仍确认 `town-source-fangwu01` 为 `128×176`、`split_lines=[96,144]`。最终仍需在原生 WebView 中完成后方/中段/屋檐前方三条路径的人工画面验收，不能用浏览器回放代替。
- 2026-11-17 三段房屋异常的兼容兜底：针对用户复测 `town-source-fangwu01` 仍可能被旧发布目录标成 `object/y_sort`、拆分线别名为空或包含无效高度的问题，`town-depth.js` 现在识别房屋名称、子分类和资源 ID（含 `fangwu/house`）并自动启用三段拆分；拆分线解析支持数组、JSON 字符串及旧响应的 `{lines:[...]}` 包装，过滤后为空时按 16 像素网格生成确定性三段边界，避免整张素材回退到单一 y-sort 深度平面。入口缓存版本提升为 `20261117-house-split26`。新增回归覆盖：房屋旧分类自动拆分、无效拆分线回退三段、既有 `[96,144]` 三段前/中/后深度规则。
- 本地证据：`node scripts/verify-town-sort-mode.mjs` 通过；`town_asset_sort_mode_test.php` 15 项通过；`town.js`、`town-depth.js` 语法检查通过；`DeskBox.csproj` Debug 构建 0 错误、18 个既有警告。已停止旧实例并从 canonical Debug 路径启动唯一进程 PID 3200：`src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`，输出目录已确认使用 `house-split26`。原生画面三条路径仍需人工复测，验收标准保持为上段前景、中段连续且随人物脚底排序、下段始终背景。
- 2026-11-17 当前复核记录（以本条为当前验收状态）：`house-split27` 已在工作区，图集首次解码和分段绑定前同步实际帧尺寸，独立切片绑定后清除裁剪状态。`town-source-fangwu01` 的回归样例为 `128×176`、拆分线 `[96,144]`：上段 `0–96` 固定前景，中段 `96–144` 按脚底排序，下段 `144–176` 固定背景。排序专项脚本、两个 JS 语法检查和限定文件的 `git diff --check` 均通过；完整 DeskBox x64 测试 **5358/5358** 通过；canonical 非平台 Debug 构建 **0 错误、9 个既有警告**。停止旧实例后已启动唯一仓库实例 PID `38788`，路径为 `D:\work\C#\deskbox\src\DeskBox\bin\Debug\net10.0-windows10.0.22621.0\DeskBox.exe`。本次未部署 Web/数据库，也未发布安装包。原生自动化初始化失败（`failed to write kernel assets`），因此仍缺此新实例中三条路径的真实画面验收；此前关于缓存、裁剪或尺寸的推断不能视为用户所见故障的已确认根因，最终以原生复测结果为准。
 - 2026-11-18 房屋遮挡人工验收完成：用户在当前唯一 canonical Debug 实例中确认 `town-source-fangwu01` 的三条路径均正常——人物从房屋后方经过时下段在人物后，进入中段墙体时中段连续可见并按脚底排序，走到屋檐前方时上段在人物前。至此房屋前后景拆分不再是第一版阻塞项；后续只保留自动命名和批量确认预览优化。
- 2026-11-18 素材本地化缓存首版：客户端启动共享小镇时先读取世界接口公布的素材发布版本和配置哈希；本地命中 `catalog-{release}-{config_sha256}.json` 时跳过素材目录接口，随后按目录中的图集哈希复用已下载 PNG。新发布版本、配置哈希变化、缓存文件损坏或 PNG 校验失败时自动回退到认证接口并原子替换缓存。`TownAssetCacheTests` 新增目录命中、版本/哈希不匹配、非法元数据和临时文件清理覆盖；`SharedTownSessionTests` 覆盖命中不请求目录和发布变化重新保存目录。首次目录下发会让前端请求全部发布图集，后续启动主要读取本地目录和 PNG。
 - 2026-11-18 小镇时区统一为北京时间：PHP 世界时钟公开 `time_zone=Asia/Shanghai`，客户端状态栏显示“北京时间”，Worker 的确定性日程在未配置 `TOWN_TIME_ZONE` 时默认按 `Asia/Shanghai` 解释；Docker 示例已加入该配置。小镇时间仍是由 Worker 推进的服务器模拟时间（第几天/模拟时分），不是直接读取电脑当前实时时钟。`DeskBox.NpcWorker.Tests` 35 项、`town_world_clock_test.php` 14 项通过；canonical Debug 已按仓库流程重建并重启。
- 2026-11-18 方案状态收口：重新执行公共事件（12 项）、世界时钟（14 项）、社会事务、Worker（35 项）、实时同步、居民面板和自动识别回归，全部通过。修正本方案中“安装器尚未发布”和社会事务 Worker 测试数量为旧值的历史描述；`v2026111801` 已合并到 GitHub `main`，发布清单和公网镜像均为当前版本。下一步优先级保持为真实认证客户端验收 P1-1/P1-2/P1-3/P2-1，以及在功能开关关闭状态下完成 P2-2 社会事务并发、权限和失败回滚验收。
- 2026-11-18 P2-3 首版快照保存：新增迁移 `052_town_world_snapshots_v1.sql`、服务端快照编码/哈希校验、认证读取最新快照接口和 Worker 租约接口；Worker 每 10 分钟生成一次不可变恢复点并保留最近 20 个。PHP 结构/确定性哈希测试、Worker 36 项和 Worker 构建均通过。当前代码已推送到 `codex/town-prototype`，但迁移、PHP 文件和 Worker 镜像尚未部署到线上；增量事件日志、受控重放写入和灾备演练仍未完成。
- 2026-11-18 P2-3 线上首批部署：远程 MySQL 已执行 `052_town_world_snapshots_v1.sql`，确认 `town_world_snapshots` 及全部字段存在；三个 PHP 文件已上传到正确 `/api/_app`、`/api/v2/town` 路径，远程 SHA-256 与本地一致，PHP lint 通过。未认证公网请求返回应用层 JSON 错误，未出现 Nginx 404；尚未用真实认证会话创建快照，Worker 容器仍为旧镜像，等待新 Worker 镜像发布后再做端到端自动快照验证。
- 2026-11-18 P2-3 Worker 发布与自动快照验证：远程构建并切换 `deskbox-npc-worker:v38-p2-snapshots`，旧 `v37-p1p2-plan-validation` 容器改名保留用于回滚；健康接口返回 `ok=true`，重启次数为 0，Worker 的租约、NPC 快照、行动请求持续返回 HTTP 200。新 Worker 已在租约内实际创建快照 `4d69801bda93d119ed06e34750bb3cc1`。仍缺真实认证用户读取快照的验证，以及事件日志、受控恢复和灾备演练。
- 2026-11-18 P2-3 增量 journal 首版：新增 `053_town_world_event_journal_v1.sql` 和 `town_world_event_journal.php`；公共世界事件按 `public_event:<event_id>` 幂等追加，快照 `event_cursor` 优先使用 journal 序列，迁移缺失时保留滚动发布兼容。`town_world_event_journal_test.php`、公共事件回归和迁移结构测试通过；线上迁移执行成功，journal 已有 4 条记录，重启 `v38-p2-snapshots` 后新快照 `9e4e3093eb5d7a1139067569c6f156a1` 的 `event_cursor=3`，随后 journal 最新序列为 4。仍缺事件增量重放、受控恢复写入和灾备演练。
- 2026-11-18 P2-3 Journal 查询与 dry-run 重放：`api/v2/town/agent/journal.php` 增加 `require_once` 防止线上重复声明；新增 `town_world_replay.php`、`api/v2/town/agent/replay.php` 和 `town_world_replay_test.php`。dry-run 校验快照 SHA-256、Journal 游标/顺序/幂等键、JSON payload 及 `public_event` 与权威事件表的一致性，任何错误只生成报告，不写生产状态。相关 PHP 已备份到 `/root/deskbox-backups/town-replay-dry-run-20261118-1608/` 并上传，远程 PHP lint 通过；服务身份线上 Journal 查询返回 `cursor=20`、20 条事件，线上 dry-run 返回快照 `976d7b3f375ee834e57f895da9c295b4`、增量 3 条、`writes_applied=false`、错误 0、警告 0。仍缺真实用户快照读取、受控恢复写入和灾备演练。
- 2026-11-18 P2-3 受控恢复闸门：新增 `api/v2/town/agent/restore.php`，只允许 Worker 服务身份和有效租约，要求 `apply=true`、快照 SHA-256 和当前 `expected_world_version`；快照后存在 Journal 事件时拒绝恢复，避免在没有事件适配器时猜测状态，事务失败会回滚。`town_world_snapshot_restore()` 已通过 PHP 语法检查并部署，线上使用当前快照做安全拒绝验收返回 HTTP 409 `recovery_requires_replay`，未修改生产状态。当前仍缺事件适配器、成功恢复写入和灾备演练。
- 2026-11-19 P2-3 事件适配器与恢复写入：`town_world_replay_apply_event()` 现在按顺序处理 `time_advanced` 和 `weather_changed`，并恢复公共事件记录；恢复前先还原快照时钟，所有未知事件类型继续 fail-closed。新增回归断言验证时间事件确定性推进。线上执行一次受控恢复后，dry-run 读取新快照 `5e2b92050cb010beae868ddd8338fefb`，`world_version=301`、`change_cursor=462`、`event_cursor=55`、错误 0；Worker 镜像 `v38-p2-snapshots` 运行中且重启次数 0。NPC 行动、交易和社会事务事件适配器以及完整灾备演练仍未完成。
- 2026-11-19 P2-3 重放能力报告：dry-run 新增 `replayable` 和 `replay_blockers`，格式正确但尚无适配器的事件不会被误报为可恢复。线上对早期快照 `9e4e3093eb5d7a1139067569c6f156a1` 检查到 59 条增量，其中 10 条为未适配 NPC 事件，返回 `replayable=false`、错误 0；当前最新快照的时间事件仍可安全恢复。下一步优先补齐 NPC 任务事件的完整状态差异或继续保持 fail-closed。
- 2026-11-19 P2-3 NPC 休息任务适配器：核对线上 `npc_task_completed` 事件后确认当前样例只包含 `action=rest`、任务 ID、完成状态和最终坐标；新增确定性适配器，按公共事件表中的 `actor_npc_id` 恢复 NPC 运行坐标/休息状态并结案对应任务，其他动作仍 fail-closed。远程 PHP 8.2 lint 通过；早期快照 dry-run 从原先 10 个阻塞事件变为 `replayable=true`、67 条事件、错误 0、阻塞 0。未对旧快照再次执行写入恢复，避免覆盖当前线上世界；完整交易、农田、经济和社会事务适配器仍待实现。
- 2026-11-19 回归夹具修复：`town_model_cost_test.php` 改用相对当前时间的测试记录，避免固定日期跨过 7 天统计窗口；没有启用费用报表或模型连接测试功能。当前 37 个 `town_*_test.php` 全部通过。
- 2026-11-19 NPC 交易重放适配器：公共任务完成事件中的 `trade` 结果现在经过受限投影；恢复前校验卖方、买方、物品、数量和金额，按交易 ID 幂等调用既有原子交易服务，重复事件不会重复扣款。线上 PHP 8.2 lint 通过，最新快照 dry-run 返回 13 条增量、`replayable=true`、错误 0、阻塞 0；交易/经济之外的工作副作用仍需加入完整差异事件后再恢复。
- 2026-11-19 线上兼容收口：公共事件投影新增的 JSON 异常处理改为显式变量捕获，保持 PHP 7.4 CLI 可解析；远程 `/api/_app/town_world_events.php` 备份并上传，PHP lint 通过，远程 SHA-256 与本地一致。最新线上 dry-run 返回 `replayable=true`、错误 0，Worker `v38-p2-snapshots` 仍运行中且重启次数 0。
- 2026-11-19 工作副作用重放准备：恢复器已支持受限的 `npc_task_replay_delta`，可按 `work_result` 应用农田浇水、市场补货、工坊产物和工作收入，并用 `work_run_id` 幂等；公共投影允许受限携带 `work_result/work_point`。当前生产任务完成流程尚未生成该 delta 事件，因此线上 dry-run 仍只报告现有事件，不能把工作恢复标记为已完成；后续需在任务事务内追加事件并补真实工作样例验收。
- 2026-11-19 工作副作用重放本地收口：任务完成事务现在为每个工作任务追加一条 `task_replay_delta`，公共投影保留 `job`、`work_run_id`、`work_point` 和受限 `work_result`，恢复器按相同 payload 读取并以 `work_run_id` 幂等；修复了 PHP 异常变量丢失和重复追加事件问题。农田、市场、工坊、事件投影和重放回归通过，当前尚未部署本次改动，仍需线上备份、上传、PHP lint、远程哈希回读、真实工作事件和恢复验收。
- 2026-11-19 社会事务重放本地适配：新增社会事务承诺状态和事务结案事件的确定性恢复适配器，支持 `assigned/executing/completed/failed/cancelled`、版本推进、终态冲突拒绝和重复事件幂等；dry-run 和 SQLite 恢复回归通过。尚未部署线上，社会事务仍需线上功能开关关闭状态下完成真实认证验收和灾备演练。
- 2026-11-19 P2-3 恢复适配线上发布：远程服务器 CLI 为 PHP 7.4.33；兼容收口后上传 `town_npcs.php`、`town_world_events.php`、`town_world_replay.php`，三个文件远程 PHP 7.4 lint 均通过，远程 SHA-256 分别为 `7357f6404047f11a3be9e2e31ed5f55f597d2bc8890aa235313be55102ce1ef6`、`640a7bae703a21f96e50d58bd5e5dd02998dc883fa8ad5acd213a3c378bceecb`、`d4ebdfda7a3fc2aa8598edd02c7512c9b37040fca9794518a76629a0dbf8dbe4`，与本地一致。线上 Worker `deskbox-npc-worker:v38-p2-snapshots` 仍运行；真实工作/社会事务事件的 dry-run 与恢复写入尚未执行。
- 2026-11-19 P2-3 NPC 行走/失败适配与线上回读：恢复器新增确定性 `npc_task_completed(action=walk)` 和 `npc_task_failed` 适配，支持位置、任务状态、失败原因恢复及幂等；本地共享小镇测试增至 38 项并全部通过。线上重新上传 `town_world_replay.php`，PHP 7.4/8.2 lint 和远程 SHA-256 `ab4cea478a903dfb7ef7195656ced011200d9f05cf180e3954a8eda3470bc443` 回读通过，备份目录为 `/root/deskbox-backups/town-replay-npc-actions-20261119-013052/`。线上 dry-run 当前返回 `replayable=true`、`errors=[]`、`blockers=[]`、Journal 增量 7 条；历史 94 条工作完成事件产生于本次发布前，缺少 `task_replay_delta`，不能 retroactively 恢复，需等待发布后的真实工作事件。
- 2026-11-19 当前进度复核：线上 `deskbox-npc-worker:v38-p2-snapshots` 仍在运行，容器重启次数为 0；本地重新执行 38 个 `town_*_test.php` 全部通过，Worker 测试 36 项全部通过。方案状态表已修正为“P2-3 事件适配器已线上发布，真实工作事件/社会事务恢复和灾备演练未完成”。
- 2026-11-19 世界时钟调度修复发布：核对发现线上 Worker 仍使用旧的真实时间日程判断，导致小镇模拟时间处于工作时段时仍反复创建 `outside_schedule` 休息任务。`DeterministicSchedule.FromWorldClock(...)` 已加入按小镇第几天和模拟分钟投影日程的逻辑；新增第 1 天星期日、第 12 天上午工作时段和跨周映射测试，`DeskBox.NpcWorker.Tests` 现为 39 项全部通过。线上先备份旧 Worker 源文件，再构建并切换 `deskbox-npc-worker:v39-world-clock`，健康接口返回 `ok=true`，快照、时钟和行动请求持续 HTTP 200；切换后线上已出现农田 `water_crop` 和市场 `organize_goods` 运行任务。当前等待这些真实工作任务完成后核对 `task_replay_delta`，未人工推进或伪造生产事件。
- 2026-11-19 真实工作事件验证：`v39-world-clock` 运行任务完成后，线上产生 2 条 `task_replay_delta`，分别覆盖农田 `water_crop` 和市场 `organize_goods`，均带有 `work_run_id`、工作点、收入和受限 `world_effect`。最新快照包含 1 条快照后的 Journal 增量；使用 PHP 8.2 直接执行线上 dry-run 返回 `replayable=true`、`errors=[]`、`replay_blockers=[]`、`writes_applied=false`。尚未执行生产恢复写入，等待受控恢复授权和完成后校验；没有人工伪造事件。
- 2026-11-19 受控恢复前复核：线上只读 dry-run 继续返回 `replayable=true`、`replay_blockers=[]`、`errors=[]`、`warnings=[]`，当前快照后的增量事件数为 5；`writes_applied=false`。生产恢复接口仍未调用，等待明确授权。
- 2026-11-19 受控恢复与工作事件适配收口：按授权先完成远程数据库备份，再执行一次受控恢复。源快照 `48ee3eb921bd23af0f610aaa9b04b4d8` 重放 9 条 Journal 事件，世界版本 `301→302`，生成新快照 `791db1a73cc03740a22543358dbe2818`，`writes_applied=true`；恢复后核对农田 `farm-plot-3-1=watered`、`watering_count=8`、市场库存 `market-stock-1=96`，并确认农田工作运行记录。恢复后 Worker 新增的两个 `npc_task_completed(action=work)` 事件最初暴露为阻塞；只读检查确认其副作用由后续 `task_replay_delta` 承载，恢复器已补充仅恢复 NPC 最终位置/休息状态的 work-completion 适配器，避免重复应用工作效果。该改动已备份并上传线上，使用 PHP 8.2 dry-run 返回 `replayable=true`、`errors=[]`、`replay_blockers=[]`；未再次写入生产。完整灾备演练和认证用户快照读取仍待完成。
- 2026-11-19 社会事务重放适配补齐：恢复器新增 `social_matter_opened` 和 `social_matter_response` 的确定性校验/回放，保留终态单调性、响应幂等和冲突拒绝；`town_world_social_replay_test.php` 与全部 59 个 PHP 测试文件通过。远程备份后上传 `town_world_replay.php`，PHP 7.4/8.2 lint 均通过；最新线上 dry-run 返回 `replayable=true`、`replay_blockers=[]`、`errors=[]`、`writes_applied=false`，增量 10 条。线上当前 Journal 尚未包含新的社会事务增量，因此尚未执行生产写入；社会事务真实恢复仍需等待功能开关保持受控后产生可回放样例。
- 2026-11-19 P2-4 导航语义保存校验：素材草稿保存新增导航语义规范化和一致性校验，支持普通地面、阻挡物、楼梯、水域、桥梁及梯子/桥梁道具能力；水域缺少能力时自动补 `bridge_kit`，普通地面配置能力会拒绝，未知能力和楼梯连接方向会拒绝。新增素材服务回归断言，测试通过；`town_assets.php` 已备份至 `/root/deskbox-backups/town-assets-20261119-navigation-validator.php` 并上传，远程 PHP 8.2 lint 通过、SHA-256 与本地一致。自动导航草稿生成、受影响区块预览和确认发布仍未完成。
- 2026-11-19 P2-4 自动导航草稿：自动识别批量保存不再强制写入固定 `blocked` 导航值；服务端根据素材名称、分类、子分类和标签生成可编辑草稿，识别水域、桥梁、楼梯和道路，否则安全回退为阻挡物。新增素材服务回归断言，`town_assets.php` 和 `town-assets.js` 已备份至 `/root/deskbox-backups/town-assets-20261119-navigation-draft.*` 并上传，PHP 7.4/8.2 lint、JavaScript 语法和远程 SHA-256 回读通过。受影响区块预览和管理员确认发布仍未完成。
- 2026-11-19 P2-4 导航影响预览：新增 `town_asset_navigation_preview()`，只读汇总当前草稿/发布素材在已放置对象上的导航类型和受影响区块，返回对象数、区块数及预览 SHA-256；后台新增“预览导航影响区块”入口和表格，不修改对象、不发布素材。素材服务、后台入口和素材视图已备份至 `/root/deskbox-backups/town-assets-20261119-navigation-preview.*` 并上传，PHP 7.4/8.2 lint、素材回归测试和远程哈希回读通过。下一步是把该预览指纹接入发布确认和导航版本审计。
- 2026-11-19 P2-4 发布确认闸门：素材发布表单在存在导航预览时携带 `navigation_preview_sha256`；服务端重新计算当前影响预览，指纹不一致返回 `navigation_preview_stale`，要求管理员重新预览后才能发布。新增回归断言覆盖过期指纹拒绝；`admin_web.php`、素材视图已备份并上传，PHP 7.4/8.2 lint 和远程 SHA-256 回读通过。导航版本/发布人/预览指纹的独立审计表仍未建立。
- 2026-11-19 P2-4 发布审计收口：新增迁移 `054_town_asset_release_audit.sql` 和 `town_asset_release_audits`，远程执行成功并核对 7 个字段、发布版本唯一约束和创建时间索引。`town_asset_publish()` 在发布成功后记录 `release_id`、`release_version`、`published_by`、`navigation_version`、`navigation_preview_sha256` 和时间；旧数据库缺表时兼容跳过审计写入，不阻塞既有发布。上传 `town_assets.php`、`admin_web.php`、`admin/index.php`、`views/town_assets.php`、`town-assets.js` 后，远程 PHP 7.4/8.2 lint 通过，远程 SHA-256 与本地一致；后台入口的 PHP 7.4 兼容性也已收口。完整本地 PHP 测试 59/59 通过，P2-4 首版闭环完成。后续仅剩审计查询页和回滚演练。
- 2026-11-19 P2-4 审计查询与回滚演练：后台素材状态新增最近发布审计记录查询，显示版本、发布人、导航版本、预览指纹和时间；表缺失时保持滚动部署兼容。新增回归断言验证审计记录能从后台状态读取；本地素材服务测试通过。线上备份并上传 `town_assets.php`、`views/town_assets.php`，PHP 7.4/8.2 lint 和远程 SHA-256 回读通过。线上当前版本 v22、前一版本 v21 完成可逆演练：v22 → v21 → v22，两个阶段均核对 release_id、release_version、config_sha256，最终恢复 v22，素材对象数 133 未改变。审计表当前记录数为 0，因为最近历史发布早于 054 迁移；下一次新发布会开始产生记录。
- 2026-11-19 P2-5 遥测线上核对：确认远程已存在 `town_agent_runs`、`town_npc_activity_logs`、`town_agent_token_reservations`、`town_model_provider_revisions` 和 `town_agent_leases`；Worker 运行记录接口和后台报表文件远程 SHA-256 与本地内容一致（仅换行格式差异）。本地 `town_agent_observability_test.php` 通过，报表覆盖调用次数、成功/回退/失败、输入/输出/总 Token、平均延迟、按 NPC 汇总、结构化活动日志和配置修订；对话原文、密钥和图片内容不进入遥测记录。费用展示保留为价格快照参考，不作为包月服务的实际计费依据。
- 2026-11-19 P2-5 PHP 7.4 兼容修复：远程 lint 发现 `town_agent.php` 的可选迁移异常处理使用了 PHP 8 的未命名 `catch` 语法；已将 4 处改为显式异常变量，保留旧迁移兼容逻辑。`town_agent_observability_test.php` 通过，远程 PHP 7.4/8.2 lint 通过，远程 SHA-256 `9188c5303b6282d30d2c1fb8fff58a31e3f58da07d065781c7b25d646a964e9f` 与本地一致，备份位于 `/root/deskbox-backups/town-agent-observability-20261119-compat/`。
- 2026-11-19 P2-2 线上状态复核：Worker 容器 `deskbox-npc-worker:v39-world-clock` 未设置 `TOWN_SOCIAL_MATTERS_ENABLED`，社会事务功能仍关闭；远程数据库当前只有 1 条历史 `acceptance_probe` 事务（`cancelled`）和 1 条响应，没有承诺任务。未伪造新的生产事务或事件；P2-2 仍需在功能开关关闭状态下由认证管理员创建真实受控样例，再验证候选资格、并发响应、路线/库存条件和失败回滚，之后才能开启开关。
- 2026-11-19 P2-2 PHP 7.4 兼容收口：社会事务服务中的未命名 `catch` 已改为显式异常变量，`array_is_list` 替换为兼容列表校验；Agent 社会事务入口的 PHP 8 `match` 改为 `switch`。本地社会事务回归和全部 59 个 PHP 测试通过；远程备份并上传 `town_social_matters.php`、`api/v2/town/agent/social.php`，PHP 7.4/8.2 lint 通过，SHA-256 分别为 `aa7d49910a4f57a8f2e130eb8f2a123c3d4bd1bf4436b34190acff93fe2f19f9`、`e1a53382d47ac62a0b9f8ad061497899f7ebd780ad779a83eb3de8c5662537f7`。功能开关仍保持关闭。
- 2026-11-19 P2-3 线上只读灾备复核：使用 Worker 服务身份执行最新快照 dry-run，快照 `035ccbf92bbf4de77df3b8ffac9b1ccb`、世界版本 `302`、导航版本 `297`，快照后 Journal 增量 2 条（序号 `867–868`），返回 `replayable=true`、`replay_blockers=[]`、`errors=[]`、`writes_applied=false`。农田浇水次数 8、市场库存 96 等恢复后状态仍在快照中；本轮未写入生产，完整灾备演练仍需新的受控恢复授权和认证用户快照读取证据。
- 2026-11-19 P2-2 远程 MySQL 并发验收：在生产数据库上运行只读结构检查和临时表锁探针，确认 `town_social_matters`、`town_social_responses`、`town_social_commitments` 存在，`task_id CHAR(32) NULL` 和任务索引正确；第二连接在第一连接持有 `FOR UPDATE` 时按预期收到锁等待错误，临时表已清理。社会事务功能开关未开启，未改变真实事务数据。
- 2026-11-19 P1-4 图片对话服务端发布：确认线上 Worker 内部 `/internal/photo-facts` 已启用鉴权，未带服务令牌返回 403；上传 `town_npc_photo.php` 和 `api/v2/town/npcs/conversations/photo.php`，远程 PHP 7.4/8.2 lint 通过，匿名公网请求返回应用层 401 `invalid_session`，没有 Nginx 404；远程 SHA-256 与本地一致。Worker 测试 39/39 通过，覆盖 metadata fallback 不携带图片字节。桌面端已接入居民对话图片按钮、隐藏文件选择器、Base64 Bridge、multipart 上传和识别结果/回复渲染；同时将 WebView 消息上限调整为 8 MiB，以覆盖浏览器端 4 MiB 图片的 Base64 膨胀。仍待真实登录会话选择图片验收，完成后再发布桌面端。
- 2026-11-19 P1-4 客户端上传回归：`SharedTownSessionTests` 增至 20/20，覆盖认证 multipart 字段、PNG MIME、文件内容、非法类型和超过 4 MiB 拒绝；canonical Debug 构建 0 错误并重启，运行路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。真实登录会话图片选择和线上桌面端发布仍待完成。
- 2026-11-19 P1-4 前端图片校验回归：新增 `residentPhotoValidation()` 并接入居民对话图片选择，`scripts/verify-town-residents.mjs` 验证 PNG/JPEG/WebP、4 MiB 边界、空文件和不支持格式；canonical Debug 构建 0 错误并重启，运行 PID 27472。真实登录会话图片选择和线上桌面端发布仍待完成。
- 2026-11-19 P1-4 4 MiB 边界上传回归：`SharedTownSessionTests` 的合法图片用例改为完整 4 MiB PNG，验证 multipart 序列化确实携带完整负载；20/20 通过。真实登录会话图片选择和线上桌面端发布仍待完成。
- 2026-11-19 共享小镇自动回归复核：本地逐个执行 38 个 `server/deskbox-api/tests/town_*test.php`，全部通过；图片接口 PHP lint 和前端居民脚本验证也通过。剩余图片验收仍是需要真实登录会话的运行时步骤。
- 2026-11-19 真实 Debug 会话复核：已检测到“小镇预览 · 实验”窗口并确认资产目录和 NPC 图集加载成功；随后 `world/chunks`、NPC snapshot 和居民读取请求连续出现 `TaskCanceledException/HttpRequestException`，桌面 WebView 无障碍树和窗口捕获也超时，因此本次不能把图片对话运行时验收标记为通过。需要先恢复该会话的 API 请求连通性，再继续选图验收。
- 2026-11-19 真实会话网络复核：通过 SSH 检查线上 `deskbox-npc-worker` 容器健康，Worker 对认证 NPC snapshot 请求约 140–155 ms 且持续 200；匿名/无效令牌 API 请求可在约 0.5–1.3 秒返回 401。当前只剩桌面客户端会话在成功加载资产后对世界分块、NPC snapshot 和居民读取发生超时，尚未完成图片按钮运行时验收。

## 2026-11-19 线上部署记录

本次已将共享小镇线上运行时部署到 `sltbox.2leaves.cn`：

| 项目 | 结果 | 证据 |
|---|---|---|
| 远端备份 | 已完成 | `/root/deskbox-backups/town-deploy-20261119-132402/`，包含 `api-before.tar.gz` 和 `database-before.tar.gz` |
| Web 运行文件 | 已上传 | `server/deskbox-api/api` 运行目录已同步，未包含本地配置和 `_private_uploads` 素材 |
| 数据库迁移 | 已执行 | 039–054 共 16 个迁移全部返回 `OK`；迁移为幂等增量脚本 |
| PHP 语法 | 已通过 | 线上 PHP 8.2 对 API 下 146 个 PHP 文件检查，失败数为 0 |
| 文件回读 | 已通过 | `town_npc_photo.php`、图片对话接口、`town_world_replay.php` 远端 SHA-256 与本地一致 |
| 匿名接口 | 已通过 | 图片接口返回 405（仅允许 POST），NPC 快照/世界接口返回预期 401 `invalid_session` |
| NPC Worker | 已运行 | `deskbox-npc-worker` 容器状态为 running |

本次部署只覆盖 Web/PHP/数据库运行时；桌面端 Debug/安装包没有在本次线上部署中更新。已登录账号下的完整图片对话、跨账号和客户端画面验收仍需在可用客户端会话中单独确认。
- 2026-11-19 客户端网络稳态补强：`SharedTownSession.SendAsync` 对幂等 GET 增加一次 250ms 延迟重试，超时和瞬时 `HttpRequestException` 可恢复；POST/动作/邀请/上传仍只发送一次，避免重复写入。新增 `IdempotentWorldReadRetriesOneTransientFailure` 回归测试；`SharedTownSessionTests` 21/21 通过。按仓库流程重建并启动 canonical Debug，当前进程为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`（PID 25468）。该改动尚未发布桌面安装包，需真实登录会话复测后再进入发布。
- 2026-11-19 客户端候选发布包：修复小镇图片错误响应和 NPC 邀请中的 Native AOT 动态匿名类型序列化，`publish-aot-retail.ps1 -Platform x64` 在独立目录 `.artifacts/town-retry-release/win-x64` 成功完成，`unexpectedWarningCodes=[]`，产物 8764 个文件、约 431.5 MiB；`release-manifest.json` 和 `SHA256SUMS` 已生成，候选版本标记为 `2026111901`。这是未上传的候选包，正式 `stable.json`、GitHub Release 和镜像保持不变，等待真实登录会话验收后再发布。
- 2026-11-19 候选安装器：系统已有 Inno Setup 6；基于候选 Native AOT 目录生成 `SltBox_Setup_2026111901_x64_2026111901_x64.exe`，文件大小 158,727,072 bytes，ProductVersion `2026.11.19.1`，SHA-256 `477DA0F15E37DDD7894CAE836B906038D0A23B0158377854BF1CE122CBD92199`。安装器和校验文件位于 `.artifacts/town-retry-release/win-x64/installer-2026111901/`，仅为未上传候选包；正式发布仍等待真实会话验收。
- 2026-11-19 `2026111901` 已完成线上发布：标准命名 x64 Native AOT 安装器 `SltBox_Setup_2026111901_x64.exe`（158,727,072 bytes，SHA-256 `477da0f15e37ddd7894cae836b906038d0a23b0158377854bf1ce122cbd92199`）已创建 GitHub Release `v2026111901`，并上传到 `http://sltbox.2leaves.cn/releases/`；`update/stable.json` 已同步版本、下载地址、大小和哈希。GitHub 资产、镜像 HTTP 响应和完整公开下载回读均通过，公开下载哈希与本地一致。真实登录图片对话运行时验收仍需单独完成。
- 2026-11-19 发布后复核：本地 38 个 `town_*test.php` 全部通过（其中 3 个需要 DSN 的 MySQL 测试按规则跳过），Worker 测试 39/39 通过，客户端共享小镇相关测试 36/36 通过；当前工作区变更涉及的 45 个 Web/PHP 文件逐项与线上 `/www/wwwroot/sltbox.2leaves.cn` SHA-256 对比无差异。GitHub `main` 的 `update/stable.json` 已确认指向 `2026111901`，GitHub Release 和 HTTP 镜像大小、哈希一致。剩余未完成项仍是 P1-4 图片对话真实认证运行时验收、P2-2 社会事务开关关闭状态下的认证并发/权限/失败回滚验收、P2-3 完整灾备演练，以及原生 WebView 的最终窄屏视觉回归。
- 2026-11-19 线上 PHP 运行时复核：排除 `/api/_deploy-backups/` 后，线上 PHP 8.2 CLI 对 142 个活动 PHP 文件执行 `php -l`，失败数为 0；服务器默认 PHP 7.4 CLI 仅用于兼容性抽查，不能替代 PHP 8.2 运行时检查。
- 2026-11-19 本机安装器状态：运行 `SltBox_Setup_2026111901_x64.exe` 时检测到 `D:\kaifa_tool\deskbox-smoke-install` 和仓库 Debug 输出目录两个已有 DeskBox 安装，安装器按保护逻辑暂停，未覆盖或卸载任何版本。需要用户决定保留哪个本机版本后，才能继续进行安装后的真实登录会话验收。
- 2026-11-19 会话过期地图请求修复：`SharedTownSession` 收到区块接口 401 时现在立即清空区块、关闭小镇会话并显示“登录已过期，请重新登录后查看共享小镇”，后续 viewport 事件不会继续每 20 秒重复请求。新增 `ExpiredSessionStopsRepeatedChunkRequests`，共享小镇客户端测试 22/22 通过；Debug 构建 0 错误并按流程重启 canonical 实例 PID 25808。该客户端修复尚未进入线上安装包，需下一次真实登录会话复测后再发布。
- 2026-11-19 本轮线上部署：已先创建远程备份 `/root/deskbox-backups/deploy-20261119-190859/`（API 与 database），将当前 `server/deskbox-api/api` 运行目录同步到 `/www/wwwroot/sltbox.2leaves.cn/api`（保留远端 `_private_uploads` 和配置），并上传全部数据库迁移文件。远程 MySQL 逐项重放 039–054，全部完成；PHP 8.2 对 142 个活动 PHP 文件 lint 失败数为 0。Worker 使用当前源码构建并滚动升级为 `deskbox-npc-worker:v40-world-clock`，健康检查返回 `ok=true`、`realtime.activeConnections=1`，旧 `v39-world-clock` 容器保留为停止状态回滚副本，备份位于 `/root/deskbox-backups/worker-v40-20261119-111716/`。匿名世界接口继续返回预期 `401 invalid_session`。本轮未重新发布桌面端安装包，客户端会话过期修复仍需真实登录验收后再进入正式桌面发布。
- 2026-11-19 P2-3 受控灾备写入演练完成：在 Worker 有效租约下使用最新快照 `6eec4c08a74a7cf07c7fa16153b293fa` 执行一次真实恢复，服务端返回 `ok=true`、`writes_applied=true`，恢复后世界版本为 `303`，并生成恢复后快照 `814887a0306b36bb7191b15791045c3b`（SHA-256 `11914c2a0d474330c52b172d857bc47e4a8647ddb9ad91abbcbae8467dc5209e`）。恢复前 Journal 增量 11 条（序号 `1580–1591`）；随后 dry-run 从 `1591` 到 `1592` 仅发现 1 条增量，返回 `replayable=true`、`replay_blockers=[]`、`errors=[]`，确认恢复后的状态仍可继续回放。农田 `farm-plot-3-1` 保持 `watered / watering_count=8`，市场库存 `market-stock-1` 为 `104`，导航版本 `298`。P2-3 的线上写入演练和幂等前置证据已完成，后续只需按运维周期重复演练，不再作为第一版阻塞项。
- 2026-11-19 桌面端正式发布 `2026111902`：统一项目、包清单和安装器版本后重新生成 Native AOT x64 安装包 `158437535` bytes，SHA-256 `a220769fb988798109258077ac13df104e811ba0fbbc650f2047d264e10e9c4c`。GitHub Release `v2026111902`、HTTP 镜像 `/releases/` 和公开完整下载回读均匹配；`update/stable.json` 已线上更新到该版本。源码提交 `4e9e2649` 已进入 `main`，后续文档记录提交为 `3aad3d62`。
- 2026-11-19 桌面端发布闸门复核：按 `dotnet test .\\tests\\DeskBox.Tests\\DeskBox.Tests.csproj --no-restore --verbosity:normal -p:Platform=x64` 重新执行，5368/5368 通过、0 失败、0 跳过；构建 0 错误，只有既有警告。此前记录的 15 个 AOT Stage 5 基线失败已不再复现，不能继续作为当前发布阻塞证据。正式安装包仍保持 `v2026111902`，因为本轮没有新的桌面应用代码提交。
- 2026-11-19 线上部署复核：当前源码 API 运行目录和全部数据库迁移文件再次同步到 `sltbox.2leaves.cn`；先创建远端备份 `/root/deskbox-backups/deploy-20261119-210545/`，远端 MySQL 幂等重放 `039–054` 全部成功。PHP 8.2 对 142 个活动 PHP 文件检查失败数为 0；代表性 API 文件远端 SHA-256 与本地一致；世界和 NPC 快照匿名请求分别返回预期 `401 invalid_session`。线上 Worker `deskbox-npc-worker:v40-world-clock` 仍为 running、重启次数为 0。本次仅更新 Web/PHP、数据库迁移和部署记录，没有重新发布桌面安装包。
- 2026-11-19 原生视觉回归复核：按仓库流程从 canonical Debug 路径启动 PID `25436`（`src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`），但当前原生窗口报告为不可用，无法取得截图或可靠执行“小镇预览”点击。因此 16:9、窄窗口、气泡和素材抽屉的原生画面验收仍保持未完成，不能用启动成功替代视觉证据。

- 2026-11-20 玩家文字对话接入 Worker：修复玩家-NPC 对话入口和连续对话函数绕过 LLM、始终返回固定模板的问题。新增 Worker `POST /internal/player-talk`，使用已配置的 OpenAI 兼容模型生成 `reply/mood/next_action`，并复用 Token 预留/结算与 Agent 运行日志；模型超时、非法 JSON、额度耗尽或 Worker 不可用时保留确定性回退。PHP 在模型调用前不持有 NPC 写事务锁，回复写回当前面对面会话气泡和连续 turn；每轮新增 `player_conversation` 记忆、坐标、玩家 ID、模型来源、心情、运行 ID 与后续意图事件。交谈任务结束后，服务端仅对允许动作重新走 `town_npc_apply_action` 校验并尝试排队，模型不能直接改坐标或数据库。线上 Worker 已切换为 `deskbox-npc-worker:v41-player-talk`，健康检查 `ok=true`、重启次数为 0；线上内部实测返回 `source=model`、自然中文回复、`mood`、`next_action` 和 `run_id`。本地 PHP 对话/居民/社会事务回归通过，Worker 测试 39/39，DeskBox canonical Debug 构建 0 错误并从 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe` 重启。线上 PHP 文件已备份并上传，三文件远端 SHA-256 与本地一致。仍需真实登录用户在休息 NPC 面前发送连续文字，核对居民资料中的聊天记录、`player_conversation` 记忆和对话后行动画面；图片对话验收继续暂缓。
- 2026-11-20 玩家文字对话超时修复：线上 Worker 日志确认模型请求通常需要约 15–20 秒，原来 Worker、PHP 和桌面客户端的 20/35/20 秒链路会在模型完成前取消请求，导致用户只看到确定性模板。`SharedTownSession.InviteResidentAsync` 现在为文字交谈使用 75 秒独立超时；PHP Worker 请求使用 70 秒；Worker 对玩家对话至少等待 45 秒，同时仍尊重后台更高的超时配置。已本地验证 PHP `town_npc_player_invite_test.php` 14 项、`DeskBox.NpcWorker.Tests` 39/39、DeskBox Debug 构建 0 错误；已上传 PHP 并回读 SHA-256 一致，线上 Worker 已切换到 `deskbox-npc-worker:v42-player-talk-timeout`，健康检查 `ok=true`、重启次数为 0，旧 `v41-player-talk` 保留为停止状态回滚容器。新增回归覆盖：玩家对话会写入 `player_conversation` 记忆和事件，模型返回的 `next_action` 会在对话任务完成后再次经过服务端校验并排队执行；真实登录账号仍需发送一次文字确认气泡显示自然模型回复，之后再核对线上记忆、运行日志和后续动作记录。
- 2026-11-20 前端回复显示补偿：确认模型回复已写入 NPC runtime 和后台记忆，但客户端在邀请响应超时或随后 NPC 快照刷新时没有把 `runtime.dialogue` 补入左下角聊天时间线，造成“后台有回复、前端聊天框为空”。`town-residents.js` 现在在面对面交谈状态下监听快照中的 `runtime.dialogue`，按 NPC、任务和文本去重后补入聊天框；邀请响应正常返回时同步记录去重键，避免重复显示。图片对话仍暂缓。已通过 JavaScript 语法检查和 PHP 对话回归；按仓库流程重建并重启 canonical Debug，运行路径为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`。
- 2026-11-20 NPC 个体数值与故事事件首版实现（本地）：新增 `055_town_npc_stats_story_events_v1.sql`，NPC 数值统一为 1～100，包含心情、健康、体能、劳动、学业、文化、社交、财富、压力、声望；普通维度使用六档“糟糕/较差/一般/良好/很好/极佳”，压力使用“轻松/较低/一般/偏高/很高/极高”。新增 `town_npc_stats`、`town_npc_stat_changes`、`town_story_events`、`town_story_event_participants`、`town_story_event_effects`、`town_story_event_policies`，服务端快照/居民资料返回数值和等级，后台村民卡片增加数值编辑与变化历史，资料页增加数值卡片。新增故事事件查询、Worker 生成和结算接口；规则模板支持集市缺货、雨天避雨、广场故事会，事件可关联 NPC、数值、记忆和公共事件时间线。Worker 已读取 NPC 数值并把它们加入 Agent 上下文，按 `TOWN_STORY_EVENT_INTERVAL_SECONDS` 调用规则事件生成接口；模型草案会经过服务端白名单审核，参与者任务、关系、记忆和数值结算均由服务端事务处理；模型草案和失败回退现在都会写入 Agent 运行日志，并把 `model_run_id` 关联到故事事件。`town_npc_stats_test.php`、`town_story_events_test.php` 各 5 项通过，新增 PHP 文件 lint 通过，`DeskBox.NpcWorker.Tests` 39/39 通过，canonical DeskBox Debug 构建 0 错误并已从规定路径启动单实例。当前迁移、Web 文件和 Worker 镜像尚未部署线上，仍需真实数据库迁移、双 Worker 并发和双客户端验收。

#### 2026-11-20 个体数值与故事事件实现更新（本地）

| 能力 | 当前状态 | 已完成内容 | 尚未完成 / 验收证据 |
|---|---|---|---|
| NPC 数值 1～100 | 线上已部署 | `town_npc_stats` 保存、1～100 归一化、等级标签、变化历史；后台初始化/手动调整强制填写原因；后台显示最近变化；快照、居民资料和 Worker 上下文携带 `values/levels/revision`；055 迁移已在远程 MySQL 创建 6 张表并通过逐表校验。 | 线上数值变化和双客户端显示尚未验收。 |
| 故事事件规则生成 | 线上已部署 | 每日上限、冷却、参与人数、启停策略；集市缺货、雨天避雨、广场故事会模板；事件写入公共时间线；Worker 按 `TOWN_STORY_EVENT_INTERVAL_SECONDS` 触发并延迟结算；无策略行时服务端使用安全默认值。 | 真实 Worker 生成一条故事事件、客户端画面和双 Worker 并发仍待验收。 |
| AI 故事草案 | 已部署待验收 | Worker 在有模型配置时读取最多 10 名村民的职业、人格、数值、运行状态和记忆，使用 OpenAI 兼容接口请求 JSON 草案；令牌预留/结算仍复用现有月度 Token 机制；模型失败、超时、配额不足自动回到规则模板。服务端只接受白名单 NPC、限定文本长度、数值 ±15、行动类型和参与人数，并将来源标记为 `model`。 | 尚未用真实模型端到端生成并验收一条线上事件；没有模型时仍可运行规则事件。 |
| 事件参与和行动 | 本地完成基础绑定 | AI 草案可为参与者提供 `walk/work/rest/talk/trade` 任务；服务端先经过现有路线、占用、道具和任务校验，成功后记录 `task_id`，失败则把参与者标记为 `blocked`，不会执行未校验的坐标或数据库写入。 | 复杂多阶段故事、事件分支和 Worker 根据事件中途重新规划仍未实现。 |
| 事件结算 | 已部署待验收 | 结算在事务中锁定事件和效果，幂等处理；应用数值变化、记忆、关系变化、参与者完成状态和 NPC 事件日志；重复结算返回 `idempotent=true`。 | 关系表/记忆表缺失时按滚动迁移兼容路径跳过；尚未做线上并发双 Worker 验收。 |
| 后台故事事件 | 本地完成基础管理 | 后台显示策略和最近 30 条事件，展示来源、参与者和效果应用数量；普通账号可读取公共事件列表。 | 还没有独立的事件详情、手动结算/重试、参与者筛选和效果编辑页。 |
| 发布状态 | 已发布待验收 | 本地 PHP lint、故事事件 10 项、NPC 数值 5 项、Worker 39 项测试、Worker Release 构建均通过；055 已执行远程 MySQL，PHP 文件远程 SHA-256 与本地一致；线上 Worker 为 `v43-npc-stats-story`，健康检查通过；canonical DeskBox Debug 已按 AGENTS.md 启动单实例。 | 真实模型故事事件、重启恢复、双 Worker 竞争、双客户端数值/故事显示和完整生产验收仍未完成。 |

本轮新增/修改的关键文件：`server/deskbox-api/database/055_town_npc_stats_story_events_v1.sql`、`server/deskbox-api/api/_app/town_npc_stats.php`、`server/deskbox-api/api/_app/town_story_events.php`、`server/deskbox-api/api/v2/town/agent/story-events.php`、`server/deskbox-api/api/v2/town/agent/story-event-resolve.php`、`server/deskbox-api/api/admin/index.php`、`server/deskbox-api/api/admin/views/town_npcs.php`、`src/DeskBox.NpcWorker/Program.cs`、`server/deskbox-api/tests/town_npc_stats_test.php`、`server/deskbox-api/tests/town_story_events_test.php`。本地验证命令和结果：`php server/deskbox-api/tests/town_npc_stats_test.php`（5 项）、`php server/deskbox-api/tests/town_story_events_test.php`（5 项）、`dotnet test .\tests\DeskBox.NpcWorker.Tests\DeskBox.NpcWorker.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64`（39/39）、`dotnet build .\src\DeskBox.NpcWorker\DeskBox.NpcWorker.csproj --no-restore -p:Platform=x64`（0 错误）、新增/修改 PHP 文件 `php -l` 全部通过、`git diff --check` 通过。

#### 2026-11-20 个体数值与故事事件线上发布

- 先创建线上备份目录 `/root/deskbox-backups/npc-stats-story-20261120-091450/`，数据库备份文件已生成；`mysqldump` 输出了 tablespace `PROCESS` 权限提示，但数据库备份文件正常生成，后续应在具备权限的备份账号上补做无提示全量备份。
- 055 迁移已在远程 MySQL 8.0.45 执行并逐项确认 `town_npc_stats`、`town_npc_stat_changes`、`town_story_events`、`town_story_event_participants`、`town_story_event_effects`、`town_story_event_policies` 共 6 张表存在。迁移脚本已修正入口常量和注释处理，重复执行安全。
- 本轮 PHP/Web 文件已上传并通过远程 PHP 8.2 lint；代表性文件远程 SHA-256 与本地一致，包括 `town_npc_stats.php`、`town_story_events.php`、三个 Agent 故事接口和后台 `town_npcs.php`。
- Worker 已用 Release 发布内容构建 `deskbox-npc-worker:v43-npc-stats-story`，旧 `v42-player-talk-timeout` 保留为停止状态回滚容器。线上健康接口返回 `ok=true`，容器 `restart_count=0`；最近日志未发现错误。由于当前数据库没有初始化统计行和故事策略行，服务端按代码安全默认值返回数值/策略，后台首次保存时再创建对应行。
- 本轮未发布桌面安装包；桌面仍使用已有 canonical Debug 验证实例。真实模型故事事件、重启恢复、双 Worker 竞争、双客户端数值/故事显示仍需认证验收。
#### 2026-11-20 故事事件闭环修复（本地）

- 修复 Worker → PHP 的嵌套 JSON 草案边界：`api_body()` 返回的 `stdClass` 会在 `story-events.php` 入口递归规范化；模型任务保留经过白名单校验的 `target_npc_id`、记忆坐标、时长和对话文本。测试覆盖真实 Worker 线格式、目标坐标边界和公共投影脱敏。
- 规则事件不再只写“参与者”而没有行动：首位参与者会按事件模板尝试创建面对面 `talk` 任务，仍然经过路线、忙闲、占用和道具校验；失败记录为 `blocked`，不会绕过服务器直接写坐标。其余参与者作为观察参与者，事件仍可在任务完成后结算。
- 新增服务端 `GET /api/v2/town/agent/story-events-active.php`。它返回活动事件的到期/可结算状态和生成策略预检；Worker 每 30 秒从数据库恢复活动事件，按所有任务完成、无任务开放窗口 120 秒或过期进行结算，替换 Worker 后仍可继续处理，不再依赖进程内字典。未通过策略预检时不会先调用模型或预留 Token；条件策略会根据当前天气筛选规则模板。
- 结算现在根据真实任务状态决定 `success/partial/expired`；失败或过期的未完成参与者不会获得数值、关系或记忆效果，重复请求仍返回幂等结果。公共故事接口的效果投影不再暴露私有记忆和任务载荷。
- 修复后台数值变化历史显示顺序：管理卡片读取最新 12 条变化；桌面居民资料在打开状态下比较 `revision`，优先渲染实时变化，避免旧 detail 响应覆盖新数值。
- 回归结果：`town_story_events_test.php` 10 项、`town_npc_stats_test.php` 5 项、`DeskBox.NpcWorker.Tests` 39/39、Worker 构建 0 错误；5 个 PHP fixture 的重复 `town_agent.php` 引入改为 `require_once`，相关观察、模型成本、导航和任务测试均通过。当前仍是本地改动，055 迁移、PHP/Web 文件和 Worker 镜像尚未重新部署线上；线上真实 AI 草案、重启恢复、双 Worker 竞争和双客户端数值推送仍需验收。
