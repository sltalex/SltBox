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
| Web 模型配置 | 部分完成；按本版范围冻结 | 管理后台仍可保存模型配置并查看运行排查所需的状态、错误、输入/输出/总 Token、耗时和活动记录；API Key 通过服务端加密后写入 `town_model_providers`，浏览器不回显原文；Worker 通过服务身份读取。 | 当前使用包月模型，Token 使用量报表、费用金额/单价/币种、成本快照和“测试模型连接”按 18.70 暂缓；调用频次策略仍必须继续实现并由 Worker/服务端共同限频。 |
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

1. **最终人工验收**：双账号实时画面、五人同屏、断线重连、真实楼梯/河道/阻挡素材修改后的路线重规划，以及不同人格线上对话仍未完成；用户已明确将双账号验收放到第一版最后。
2. **关系衰减规则**：关系双向写入、好感变化、记忆摘要、相关性检索、分页查看和管理员校准已经可用；关系多久未互动后衰减仍需用户选择，不能擅自写入线上关系数据。
3. **玩家经济规则**：玩家经济已经按本版范围明确暂缓；NPC 农田、集市和工坊的世界结果已经落库并可由世界快照读取。后续不得擅自增加玩家钱包、购买、领取、种植成长或钱包扣款。
4. **复杂动态寻路扩展**：二维 A*、高度、楼梯方向、桥梁/梯子能力、导航版本失效、路线缓存和交互点预约已完成首版；桥梁建造排队、复杂交互点调度和更多道具仍未实现。
5. **模型运营长期数据**：运行状态、错误、必要的输入/输出 Token 和耗时字段保留用于排查；Token 使用报表、费用金额、价格配置、供应商修订历史、成本快照和模型连接测试按用户要求暂缓，不得作为当前第一版完成条件。
6. **策略审核扩展**：对话已经具备长度、控制字符和可执行 HTML 安全过滤；敏感内容分类、人工复核和业务审核策略尚未确定，因此不应自行增加内容判定规则。
7. **长期运行验收**：短窗口租约接管、回滚和单 Worker 观察已经完成；多 Worker 长时间竞争、模型超时/确定性回退和断线重连仍需线上压力或人工验收。费用报表和 API 连接测试不属于本版验收。

已完成但曾在旧版本记录为“未完成”的项目包括：NPC 完整管理表单、关系/记忆查看与清除、NPC 道具库存和预约事务、Agent 运行日志、严格 steps/capability 校验、区块路线缓存和基础跨高度导航。旧版本曾实现的 Token/费用报表代码属于历史能力，按 18.70 的当前权威口径暂停扩展和验收；以后以本节和 18.x 时间线中较新的用户范围记录为准。

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

建议按以下顺序继续，不要把已完成的首版闭环重复实现：

1. 先按 18.70 完成账号/玩家装饰边界、NPC 家、NPC 经济、环境观察记忆、调用频次和面对面对话闭环。
2. 关系衰减、内容审核分类、桥梁建造排队等尚未确认的扩展继续保持独立，不得阻塞本版核心闭环。
3. 补齐不依赖上述扩展的运维验收，例如复杂桥梁建造排队以外的长窗口多 Worker 压力验证，以及管理员登录态素材导航编辑、导航重建和路线重规划检查。
4. 第一版其他功能稳定后，按用户安排执行双账号、五人同屏、断线重连和不同人格对话的最终人工验收。
5. Token/费用报表和模型连接测试保持暂缓；所有第一版验收证据完成后，再把方案总状态从“进行中”改为“已完成”。数据库快照、自动化测试或容器健康检查不能替代最后一批画面验收。

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
| 1. 实时同步可靠性 | 代码和自动化验证已完成；双客户端人工验收后置 | 玩家像素坐标 10Hz 同步；PHP 逐次位置校验；通常 1Hz 持久化和停止/关闭强制保存；自动重连、重新取令牌、补快照、旧会话隔离；分片、坏消息、慢连接隔离和 HTTP 回退均已覆盖。两个真实账号的画面验证按用户安排放到最后。 |
| 2. NPC 权威执行与调度 | 首版已部署；长窗口观察仍待补验 | 服务端持久化任务和路线进度，逐格推进，到达后工作/交谈；租约续期和失租停止写入；慢模型不阻塞其他 NPC；有界重试、无模型/超时/非法输出回退、时区和导航版本重规划均已实现。多 Worker 长时间竞争和最终客户端画面仍待验收。 |
| 3. 后台与世界工作点 | 首版管理和世界结果已完成；真实后台点击仍待验收 | 村民资料、人物素材、位置、暂停/恢复、强制任务、清除记忆、日程、批量状态、关系/记忆分页和运行日志均可管理；农田、集市、工坊工作点会产生持久化世界结果。线上登录后台的实际点击回读和附近设施等增强项仍待补验。 |
| 4. 动态通行、道具与工作 | 首版已完成；复杂扩展仍待实施 | 玩家/NPC 共用导航；楼梯方向和高度转换；河道/桥梁；NPC 库存、梯子/桥梁消耗、交互点预约、资源锁；农田/集市/工坊结果和途中地图变更重规划均已有代码与测试。桥梁建造排队、复杂交互调度和真实素材画面验收仍未完成。 |
| 5. 社交与模型运营 | 基础闭环已完成；本版业务规则按 18.70 执行 | 不同人格字段、双向对话、关系变化、记忆写入/摘要/检索、安全过滤和调用冷却属于本版；Token/费用报表、价格快照、模型连接测试和玩家经济按 18.70 暂缓。关系衰减、敏感内容审核策略以及复杂交易协同仍属于后续扩展。 |
| 6. 完整第一版验收 | 进行中；双账号批次按用户要求最后执行 | 5 个不同人格/日程村民、农田工作、确定性不同风格对话、河道/阻挡/楼梯、缺道具拒绝或绕路、动态路线失效、Docker 健康检查、无模型回退、租约接管和回滚已有证据。五人同屏、双账号实时移动、断线重连、真实素材编辑后的路线画面和模型驱动人格差异仍待最终人工验收。 |

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

### 18.17 当前权威进度表（2026-11-12 更新）

以下表格覆盖前文早期“待实施”描述；继续开发时以本节及其后的更新为准。

| 模块 | 当前状态 | 已完成的可验证内容 | 仍缺少的内容 |
| --- | --- | --- | --- |
| 数据库与样例 NPC | 已上线 | 028～038 迁移、5 个样例村民、人格/家庭/目标/职业/7 天日程、运行状态、事件、日志、库存、工作点、农田/集市/工坊结果、活动预约历史；新增沈禾、顾宁各 21 条日程并由线上 Worker 运行 | 玩家经济闭环未做；5 人客户端同屏仍需真实登录画面验收 |
| 导航与通行 | 首版上线 | 2D 网格 A*、plain/blocked/stairs/water/bridge、楼梯连接方向和高度、导航版本、梯子/桥梁能力、道具消耗和失败退还、服务端逐格校验、地图变更后的主动重规划、按导航版本失效的有界进程缓存；线上已验证一次性 `bridge_kit` 跨水道 | 桥梁永久建造/排队、复杂交互点调度、真实素材编辑后的最终画面验收 |
| Worker | 上线运行 | Docker `v23`、全局 30 秒租约/续租/接管、租约状态并发保护、确定性回退、OpenAI 兼容调用、模型超时隔离、非法计划回退、Token 预留/结算、逐格 advance、健康检查和实时连接指标；已完成 Worker 回滚、双 Worker 租约竞争、线上超时回退和桥梁能力切片 | 多 Worker 长时间压力、模型质量和最终 NPC 画面行为仍需验收 |
| 模型运营 | 部分完成 | Web 配置、服务端加密、连接测试、OpenAI 兼容调用、输入/输出/总 Token、月 Token 额度和超额回退、按村民日志报表、调用前原子 Token 预留和完成后按实际用量结算；036 已上线费用单价/币种、供应商修订历史和运行成本快照表；后台已增加费用明细和修订历史只读表；线上已观察超时与非法计划回退 | 真实价格配置后的长期费用归档、额度边界长期运行和模型人格差异验收 |
| 实时同步 | 已上线代码 | WebSocket 玩家/NPC 事件、快照/增量、断线回退、旧响应过滤、NPC 逐格状态广播 | 两个真实账号的线上视觉验收、长连接压力/断线演练 |
| NPC 工作 | 首版闭环 | 农田浇水、集市补货、工坊产物、工作点预约和审计记录 | 种植成长/收获、玩家购买/领取、完整物流经济 |
| NPC 社交 | 首版闭环 | 交谈目标按对方实时位置寻路、逐格到达、双向好感、双方 conversation 记忆、确定性摘要压缩、相关性检索、输入安全过滤、事件/活动日志、后台查看和双向校准、服务端 60 秒重复交谈冷却；线上已完成一次 NPC 对话闭环 | 多人连续协同、人格差异对话策略、语义向量检索、关系衰减、敏感内容分类/人工审核 |
| Web 管理 | 基础管理已上线 | 村民 CRUD/编辑、位置、暂停/恢复、强制任务、道具、日程、关系/记忆查看和清除、关系校准、关系/记忆分页筛选、批量暂停/恢复 Agent、批量启用/停用、素材导航语义编辑/重建、模型配置和报表；Agent 页面增加逐次费用和供应商修订历史查看 | 登录后台真实点击回读、长期报表归档和待用户确认的业务规则 |
| 第一版验收 | 进行中 | 线上桥梁道具、Worker 租约接管、工作点/农田/工坊、NPC 双人对话、地图修改后重规划、5 人数据库快照、新增村民日程、无模型配置确定性回退、严格 Token 预留、v23 超时/非法计划回退、跨高度运行高度和工作点预约修复已完成 | 5 人同屏、双账号 WebSocket 画面（按用户要求留到最后）、断线重连人工验收、真实素材编辑后的最终画面、模型驱动的人格差异、关系衰减/玩家经济/审核/桥梁队列规则和成本长期归档 |

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

### 18.46 后台导航重建闭环（2026-11-12）

素材导航语义编辑已经补齐管理员可执行的版本重建入口。共享小镇素材页面现在显示当前导航版本，并提供“导航重建”按钮；按钮使用现有管理员会话、CSRF 和 `panel=town_assets` 回传，不向普通用户暴露。

- 新增后台操作 `town_navigation_rebuild`，调用服务端 `town_navigation_rebuild()`，在事务中同时递增 `town_worlds.navigation_version` 和 `world_version`。
- 重建成功后页面提示运行中的村民会在下一次行动校验时重新规划；导航进程缓存按版本失效，旧路线不会继续作为权威执行依据。
- 共享小镇素材的正常保存仍只修改草稿素材；管理员需要在修改通行语义后显式点击重建，便于一次编辑多项素材后统一刷新导航。

本地验证：`web_admin_service_test.php` 新增后台重建操作断言，确认世界版本和导航版本从 4/7 更新为 5/8；页面源码检查确认按钮、版本提示和 CSRF 表单存在。PHP 语法检查通过。

线上发布：`api/_app/admin_web.php` 和 `api/admin/index.php` 已上传到 `/www/wwwroot/sltbox.2leaves.cn`。发布前备份位于 `/root/deskbox-backups/navigation-rebuild-20261112-025046/`；使用线上 PHP 8.2 CLI 回读检查均无语法错误，远程 SHA-256 分别为 `eb06c0d0e52269b4b2f7d70e057a5fb4a76ee372e094670ae8ccb70b474382c7` 和 `1254fdf40e93e54826b4ae77de2c86ed3bcbec933d8a4743acbea24c9e3a86e2`，与本地一致。未代替管理员实际点击，线上页面真实重建操作仍留待人工验收。

当前仍未完成：线上管理员页面的实际点击回读、真实楼梯/河道素材修改后的画面路线验收，以及双账号 WebSocket 和五人同屏人工验收。这些按用户安排放在第一版最后，不用自动化测试或数据库快照替代。

### 18.47 确定性日程的 NPC 对话目标（2026-11-12）

服务端原本已经支持以具体 NPC ID 为目标的交谈，并在交谈完成后双向更新关系、写入双方记忆和广播 `conversation` 事件；但样例日程中的目标是 `town-square`，仅能把村民送到地点，不能形成 NPC 对 NPC 的交谈。

Worker 现在在执行 `talk` 前解析具体对象：

- 模型、管理员或日程已经提供有效启用 NPC ID 时，保留原目标。
- 目标是广场等地点或为空时，从当前快照中排除自己、停用和暂停村民，按曼哈顿距离选择最近的村民；距离相同时按 NPC ID 排序，结果可重复。
- 选出的目标仍交给服务端 `find_route`、碰撞、导航版本和交谈冷却校验，Worker 不直接写坐标或关系。
- 没有可交谈对象时保留原目标，任务仍由服务端按普通地点路线处理，不会伪造关系记录。

本地验证：Worker 调度测试新增“地点目标选择最近村民”和“显式 NPC 目标保持不变”两项；随后执行 Worker 单测和构建。该切片不改变关系衰减规则，也不修改玩家经济。

线上发布：Worker 镜像更新为 `deskbox-npc-worker:v23`，旧容器保留为 `deskbox-npc-worker-v22-social-target-rollback`；部署前备份位于 `/root/deskbox-backups/worker-v23-social-target-20261112-030905/`。远程源文件 SHA-256 为 `f5a221e821c56d5f52c68a5114c6c9d6b8109794db2d13201d2aa1a7d815f02a`，与本地一致；新容器 `running`、重启次数为 0，`/health` 返回 HTTP 200。线上没有修改模型配置、Token、NPC、地图或关系数据。

### 18.48 线上第一版状态审计（2026-11-12）

使用站点自身 PHP 8.2 数据库连接做只读核对，当前线上事实为：

| 项目 | 当前值 | 证据含义 |
| --- | ---: | --- |
| 启用村民 | 5 | 林野、花青、周岩、沈禾、顾宁均存在并启用，满足第一版 5 人配置门槛；不代表五人同屏画面已经验收。 |
| 村民日程 | 105 条 | 5 人各有 21 条按周重复日程。 |
| 运行状态 | 5 条 | 每个启用村民均有服务端运行状态记录。 |
| 导航版本 | 3 | 当前地图导航版本可读；素材编辑后的管理员点击和真实路线画面仍未验收。 |
| NPC 事件 / 活动日志 | 2661 / 7587 条 | Worker 已持续产生可审计运行数据；数量不能替代双账号实时显示证据。 |
| 模型配置 | 已启用，月额度 0（不限额） | 配置可读；尚未证明额度边界触发，也不代表费用报表已有真实金额数据。 |
| Worker | `deskbox-npc-worker:v23`，running，重启 0 | 健康接口返回 200；仍是单 Worker，最终双账号和断线重连画面验收待最后阶段。 |

本次审计只读数据库和容器状态，没有修改 NPC、关系、地图、模型凭据或玩家数据。它补强了线上配置证据，仍不提前宣称完成最终人工验收。

### 18.49 线上无模型回退演练（2026-11-12）

根据已授权的临时演练，使用站点 PHP 8.2 数据库连接保存 provider 和一个无运行任务样例村民的原状态，将 provider 暂时全部设为停用、临时恢复该村民，观察一个 Worker 调度窗口，再按原值恢复：

- 演练前有 1 个 provider，启用数为 1；`town_agent_runs` 为 1590 条；该样例原本 `agent_paused=1`。
- 停用模型期间 Worker 没有停止，也没有报错或重启；恢复的样例产生 2 条新运行记录，最新记录为 `fallback/rest/outside_schedule`。
- 演练结束后样例恢复为原来的暂停状态，provider 启用数恢复为 1；没有修改 API Key、模型名称、地图、关系或钱包。
- 该演练证明“模型暂时不可用时 Worker 仍按确定性日程运行”的线上服务链路；没有把它当成双账号画面或模型质量验收。

### 18.50 双账号实时验收继续后置（2026-11-12）

根据用户最新安排，双账号实时验收暂不执行，继续放到第一版其他功能完成之后的最后阶段。本次只记录执行顺序，不修改代码、数据库、NPC、模型配置或线上运行状态。

最终仍需使用两个真实登录客户端完成以下人工验收：

- 两个账号同时进入共享小镇，确认 5 个 NPC 同屏可见。
- 一个账号移动玩家后，另一个账号实时看到玩家位置变化。
- 观察 NPC 的行走、工作、交谈气泡和状态变化是否实时出现。
- 断线重连后确认快照补齐，旧事件不会回退当前状态。
- 修改真实楼梯、河道和阻挡素材后，确认 NPC 不继续执行旧路线并能显示重新规划结果。
- 验证不同人格的线上对话表现。

在这批人工验收完成前，数据库快照、WebSocket 自动化测试、Worker 健康检查和单账号页面检查只能作为辅助证据，不能标记“双账号实时验收”或“第一版最终验收”已完成。后续继续开发时，优先推进不依赖双账号画面验收的功能；完成其他项后再回到本节逐项执行并补充结果。

### 18.51 NPC/导航/实时回归核验与状态表修正（2026-11-12）

本次没有修改数据库、NPC、模型配置、地图或线上容器，只做本地回归和方案状态校准：

- `DeskBox.NpcWorker.Tests`：17/17 通过。
- `town_*test.php` 相关测试全部通过：Agent 可观测性、素材/足迹、农田、模型成本/配置、NPC 上下文、批量管理、集市/工坊、跨高度、库存预约、导航（17 项）、社交、任务执行（15 项）、玩家位置（53 项）以及小镇原型服务/接口检查均通过；仅 MySQL 专用测试因本机未配置 DSN 跳过。
- `town.js` 和 `town-realtime.js` 通过 Node 语法检查。
- 当前 canonical DeskBox 进程仍为 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe`，确认只有该路径实例运行。

同时修正了本方案早期第 17 节遗留的过时未完成清单：NPC 管理、关系/记忆查看、库存预约、Agent Token 报表、严格行动校验、路线缓存和跨高度首版已经完成；当前真正未完成项以本节、17.4 和 18.x 时间线为准，主要是用户规则选择、复杂扩展和最终人工验收。回归核验不能替代用户要求后置的双账号画面验收。

### 18.52 Worker v23 短窗口稳定性观察（2026-11-12）

通过 SSH 对线上 `deskbox-npc-worker` 做了约 90 秒、每 30 秒一次的只读采样：4 次均为 `status=running`、`restart_count=0`、镜像 `deskbox-npc-worker:v23`，健康接口均返回 HTTP 200。实时指标保持 `droppedConnections=0`；本次没有客户端连接，因此 `activeConnections=0`、广播计数不变，这项观察不包含双账号或画面验收。

### 18.53 768×768 边界与跨区块导航测试补齐（2026-11-12）

为覆盖方案第 14 节列出的地图边界和跨区块要求，`town_npc_navigation_test.php` 新增以下断言：

- 路线从 `(23,23)` 到 `(25,25)` 穿过 24×24 区块边界仍能连通，路径长度正确。
- `(767,767)` 作为最后一个合法格可通行。
- `(768,767)` 越过 768×768 边界会被拒绝，目标在地图外的路线不会创建。

导航测试由原来的 17 项增至 21 项并通过。该切片只增加回归覆盖，没有改变线上地图、NPC 位置或导航规则。

### 18.54 全量回归与契约基线收口（2026-11-12）

本轮继续实施前先执行完整回归，并把共享小镇新增代码对应的仓库契约基线补齐：

- `DeskBox.NpcWorker.Tests`：17/17 通过。
- 首轮 `DeskBox.Tests`：5350 项中 5348 项通过；两个失败均为生产代码新增后的静态基线计数，不是运行时失败。`TownAssetCache` 的临时文件替换触发了文件操作清单新增 2 个受控调用；共享小镇 WebView 桥接在 `TreeTownPrototypeWindow` 增加了 3 个 JSON 消息，原清单仍记为 1 个调用。
- 更新 `ModuleBoundaryContractTests`、`JsonSerializationBaselineContractTests` 及其阶段契约引用的明确清单后，受影响的 290 项契约测试 290/290 通过；完整套件已重新启动，待最终结果确认。
- `town_*test.php` 相关测试全部通过；MySQL 专用测试仍因本机未配置 DSN 跳过。PHP 8.2 语法检查、`town.js` 和 `town-realtime.js` Node 语法检查通过，`git diff --check` 通过。

本轮没有修改数据库、NPC、模型、地图或线上 Worker。关系衰减和玩家经济继续等待用户选择；双账号实时画面、五人同屏、断线重连和真实素材编辑后的路线画面仍按用户安排放到第一版最后。

### 18.55 线上 Worker 当前状态复核（2026-11-12）

使用 SSH 只读检查线上服务，没有执行重启、配置修改或数据写入：

- 容器为 `deskbox-npc-worker:v23`，状态 `running`，重启次数 `0`。
- `/health` 返回 HTTP 200；当前 `activeConnections=0`、`acceptedConnections=0`、`broadcasts=6`、`droppedConnections=0`。没有客户端连接，因此这些指标不能替代双账号画面验收。
- 最近 10 分钟日志只出现正常租约请求，没有发现 `error`、`exception`、`no_route` 或动作失败记录。

这项证据只确认当前线上 Worker 健康和未出现明显错误；关系衰减、玩家经济和最后的真实客户端验收仍未改变状态。

### 18.56 新增村民初始位置接入导航校验（2026-11-12）

补齐了新增村民流程中的一个完整性缺口：此前 `town_npc_upsert()` 对新增记录的 `tile_x/tile_y` 只做了非负化处理，可能把村民初始状态写到地图外、河道、森林或阻挡素材上；编辑已有村民位置时虽然已有校验，但新增流程没有复用它。

本次调整：

- 新增村民写入运行状态前调用 `town_navigation_can_traverse()`，使用服务端当前导航版本、地图边界、素材语义和 NPC 能力白名单进行校验。
- 地图外坐标和阻挡格统一返回 `invalid_npc_position`，不会创建运行状态，也不会留下半成品 `town_npcs`、profile 或日程记录；事务会完整回滚。
- 普通可通行地面仍可直接作为初始位置；已有村民的编辑、暂停、任务和位置修改路径保持原有行为。
- 为使服务层回归测试覆盖新增流程，SQLite 测试兼容了 profile upsert 和 runtime `INSERT IGNORE` 的等价语法；MySQL 线上路径继续使用原有语句。

本地验证：

- `town_npc_navigation_test.php` 从 21 项增加到 25 项，新增覆盖“普通地面创建成功、越界拒绝、阻挡格拒绝、拒绝后事务无残留记录”。
- 全部 40 个 `server/deskbox-api/tests/*test.php` 均通过；MySQL 专用的 `town_asset_mysql_subcategory_test.php` 和 `town_player_positions_mysql_test.php` 因本机没有 DSN 按预期跳过。
- `town_npcs.php` 和导航测试 PHP 语法检查通过；本切片只修改 PHP 服务端和测试，没有改动客户端、Worker、数据库数据或关系/经济规则。

线上发布状态：`api/_app/town_npcs.php` 已同步到 `/www/wwwroot/sltbox.2leaves.cn/api/_app/town_npcs.php`。发布前备份位于 `/root/deskbox-backups/npc-initial-position-20261112-044235/`；远程 PHP 8.2.34 语法检查通过，远程 SHA-256 与本地一致，均为 `189ee90c8eec6f6e4b64174914de72aa8c91ffa2bea2ba5c08f334053f1663bb`。因为没有修改 DeskBox 应用代码，不需要按客户端规则重启 canonical Debug 实例。双账号实时画面、五人同屏、断线重连、真实素材编辑后的路线画面和模型驱动人格对话仍按用户安排放到第一版最后；关系衰减和玩家经济仍等待用户选择。

### 18.57 当前版本全量回归核验（2026-11-12）

在继续下一阶段前重新对当前工作区和线上 Worker 做基线核验：

- `DeskBox.NpcWorker.Tests`：17/17 通过。
- `DeskBox.Tests`：5350/5350 通过，使用仓库要求的 x64 平台参数；本轮没有新增失败或基线漂移。
- 线上 `deskbox-npc-worker:v23` 为 `running`，重启次数为 0；`/health` 返回 HTTP 200，实时连接指标仍为 `activeConnections=0`、`acceptedConnections=0`、`broadcasts=6`、`droppedConnections=0`。
- 本轮只做回归和只读状态检查，没有修改数据库、NPC、模型配置、地图、玩家钱包或 Worker 容器。

这次核验确认当前代码基线可继续推进，但不能替代最终人工验收：双账号同时登录、五人同屏、玩家/NPC 实时移动、断线重连、真实素材导航编辑后的画面重规划和模型驱动的不同人格对话仍待最后执行。关系衰减和玩家经济规则也仍等待用户选择，未写入线上默认行为。

### 18.58 修复工作点过期预约重复失败（2026-11-12）

线上只读审计发现最近 24 小时出现 224 条 `action_failed`，全部来自 NPC `work` 动作的 HTTP 500。Nginx/PHP 日志给出的共同根因是：同一个工作点第二次产生历史预约时触发 `Duplicate entry ...-expired for key town_work_point_reservations.ux_town_work_point_reservation_status`。迁移 032 原来的唯一索引是 `(point_id,status)`，它同时错误地限制了同一工作点只能有一条 `expired` 历史记录；NPC 任务重试因此被数据库唯一键拒绝。

本次新增 `database/038_town_work_point_reservation_active_unique.sql`，迁移顺序和约束如下：

1. 先确保 `point_id` 普通索引存在，使删除旧唯一索引后工作点外键仍有受支持的索引。
2. 删除 `ux_town_work_point_reservation_status`（`point_id,status`）。
3. 增加 MySQL 8 函数唯一索引 `ux_town_work_point_reservation_active`，仅对 `status='active'` 返回 `point_id`，其他状态返回 `NULL`。因此每个工作点最多一个活动预约，但 `expired`、`completed` 历史可以无限保留。
4. 每一步都按索引是否已存在决定执行或跳过，重复执行不会重复建索引。

服务层回归用例同步增加“第二条活动预约被拒绝、同一工作点连续两次过期、第三次仍可重试”的场景。SQLite 测试使用 partial unique index 模拟线上语义，当前该文件通过 12 项检查；测试确认历史过期记录保留，活动预约仍会拒绝第二条并发记录。

线上迁移已经执行并完成核对：

| 检查项 | 结果 | 说明 |
| --- | --- | --- |
| 迁移前备份 | `town_work_point_reservations_bak_20261112050000` | 备份 9 行，原表数据可追溯。 |
| 普通 `point_id` 索引 | 已存在 | 保留外键支持索引。 |
| 旧 `(point_id,status)` 唯一索引 | 已删除 | 不再阻塞重复 `expired` 历史。 |
| 新活动唯一索引 | 已存在 | 仅 `active` 状态按工作点唯一。 |
| 当前状态分布 | `active=3, completed=3, expired=3` | 现有历史和活动预约均完整保留。 |
| 事务验证 | 通过 | 插入第二条 `expired` 成功；插入第二条 `active` 被正确拒绝。 |
| Worker | `deskbox-npc-worker:v23`，`running`，重启 0 | 本次未重启或更换 Worker。 |

本轮执行结果：全部 40 个 PHP 测试通过；其中 `town_asset_mysql_subcategory_test.php` 和 `town_player_positions_mysql_test.php` 因本机没有 MySQL DSN 按预期跳过；API 目录 91 个 PHP 文件全部通过 `php -l`。线上临时脚本已清理。只提交本轮相关的迁移、回归测试和方案文档，不把工作区内其他功能改动或输出物混入提交。

当前仍未完成的范围没有变化：双账号实时画面、五人同屏、玩家/NPC 实时移动、断线重连、真实素材导航编辑后的画面重规划和模型驱动的不同人格对话仍按用户安排最后验收；关系衰减和玩家经济规则仍等待用户选择，未擅自写入实现。

### 18.59 Worker 短窗口健康与错误观察（2026-11-12）

在不修改线上数据、不重启容器的前提下，对 `deskbox-npc-worker:v23` 做了约 3 分钟、6 次间隔采样：

| 项目 | 结果 |
| --- | --- |
| 容器状态 | 6/6 次均为 `running`，`restart_count=0`。 |
| 健康接口 | 6/6 次 HTTP 200，`ok=true`。 |
| 实时指标 | `activeConnections=0`、`droppedConnections=0`、`broadcasts=6` 保持稳定。 |
| Worker 日志 | 最近 30 分钟未发现 `action_failed`、`error`、`exception`、`no_route` 或 HTTP 5xx。 |

当前 5 个样例村民仍处于暂停状态，因此这项观察证明的是租约、快照和健康循环稳定，不能替代启用村民后的逐格工作、双账号 WebSocket 或画面验收。该观察没有改变 NPC、模型配置、地图、关系、钱包或 Worker 容器状态。

### 18.60 双账号实时验收继续后置（2026-11-12）

根据用户确认，双账号同时登录、玩家与 NPC 实时移动、五人同屏、断线重连和旧事件不回退等画面验收暂不执行，放到第一版其他功能完成后的最后批次。这个决定只调整验收顺序，不修改代码、数据库、NPC 状态、模型配置、地图或 Worker 容器。

在最终批次开始前，以下证据只能作为辅助验证，不能替代双账号画面验收：

- WebSocket 单元测试和连接协议测试；
- 单账号页面检查；
- 数据库中的 NPC 数量、事件和运行状态；
- Worker `/health`、广播计数和重连指标；
- 服务器端路线、动作校验和实时增量接口测试。

后续先推进不依赖双账号的工作：线上管理员导航编辑与重建的登录态回读、启用村民后的 Worker 长时间观察、模型超时和额度边界验证，以及用户尚未选择的关系衰减、玩家经济、敏感内容审核和桥梁建造队列规则。所有规则确认后再实现对应代码；双账号和五人同屏仍是第一版最终验收项。

### 18.61 服务端回归基线复核（2026-11-12）

在记录验收顺序后重新执行 `server/deskbox-api/tests` 下的全部 40 个 PHP 测试文件：38 个本地 SQLite/服务测试通过，2 个需要本机 MySQL DSN 的测试按预期报告 `skipped`（`town_asset_mysql_subcategory_test.php`、`town_player_positions_mysql_test.php`），没有失败项。重点切片结果如下：

- `town_npc_inventory_reservation_test.php`：12 项通过，覆盖第二条 `active` 预约拒绝、连续 `expired` 历史和过期后重试；
- `town_npc_navigation_test.php`：25 项通过，覆盖导航版本缓存失效、阻挡物、道具能力和重规划；
- `town_npc_height_test.php`：10 项通过，覆盖楼梯方向、高度路线节点和运行高度持久化；
- `town_player_positions_test.php`：53 项通过，覆盖玩家位置校验、版本冲突和实时状态边界。

这次只做本地回归和文档记录，没有修改数据库、NPC、模型、地图、钱包、Worker 或客户端，因此也不触发 DeskBox 客户端重启规则。回归通过仍不能替代后置的双账号实时画面验收。

### 18.62 线上 Worker 当前只读复核（2026-11-12）

按当前阶段只做健康检查，没有重启容器、切换模型或写入线上数据。SSH 复核结果：`deskbox-npc-worker:v23` 为 `running`，`restart_count=0`；本机健康端点返回 HTTP 200，内容为 `ok=true`，`activeConnections=0`、`acceptedConnections=0`、`broadcasts=6`、`droppedConnections=0`。最近日志持续收到 NPC 快照请求并返回 200。

当前没有客户端连接，因此 `activeConnections=0` 是“尚未开始双账号连接验收”的状态，不是实时同步通过证据。该复核不改变 NPC、地图、模型、关系、钱包或 Worker 配置。

### 18.63 线上确定性 Worker 与桥梁能力切片复核（2026-11-12）

按此前授权做了两次受控线上验证，均使用站点 PHP 8.2 运行时并在结束时恢复原状态：

1. 临时恢复林野、周岩的 Agent 约 30 秒。Worker 为两人各创建并完成了 `rest` 任务，写入 `state_changed`、`task_completed`、`agent_run` 和活动日志；当时处于日程外，没有自然行走任务。验证结束后两人均恢复为 `agent_paused=1`，位置、任务和配置没有改变。
2. 使用周岩已授权的 1 件 `bridge_kit`，将其临时放到受控测试位置，调用服务端 `walk` 行动并逐步推进到跨水道目标。服务端返回 2 格路线、任务 `completed`、终点 `(64,0)`，道具数量从 1 消耗为 0；随后脚本恢复周岩原位置 `(112,96)`、原库存和 `agent_paused=1`。恢复后的只读核对为：`paused=1`、位置 `[112,96]`、无当前任务、`bridge_kit` 数量为 0（与测试前记录一致）。

该切片证明线上服务端确实执行了确定性任务和一次性桥梁通行校验，但不证明 NPC 客户端逐格动画、WebSocket 广播或双账号画面同步；这些仍按用户安排留到最终人工验收。

### 18.64 线上模型超时回退与 Token 结算观察（2026-11-12）

桥梁切片结束后的只读日志检查发现模型接口有两次达到配置的 20 秒超时。Worker 没有退出或重启，两个运行记录均为 `status=fallback`、`error_code=timeout`、`total_tokens=0`，随后继续执行确定性 `rest`；健康接口仍返回 200，容器 `running`、重启 0。最近 10 分钟的模型运行统计为 4 次、合计 2868 Token，超时运行的预留记录已结算为 0 实际 Token，没有留下 `reserved` 状态。

同一窗口还有两次模型输出被服务端计划校验拒绝（`invalid_plan`，模型返回了不支持的步骤），运行记录保留实际 Token（1530、1338），Worker 回退到确定性行为。该证据确认超时、非法 JSON/计划和 Token 结算路径不会阻塞 NPC Worker；模型质量和人格差异仍需最终真实客户端验收。

### 18.65 第一版完成标准逐项审计（2026-11-12）

本次按第一版目标逐项检查代码、测试、线上数据库快照和 Worker 状态，结果如下：

| 完成标准 | 当前结论 | 权威证据或缺口 |
| --- | --- | --- |
| 5 个村民可显示并可配置人格/日程 | 已具备服务端闭环 | 线上 5 个启用 NPC、每人 21 条日程、快照和管理后台数据；五人同屏画面仍待最终人工验收 |
| 至少一个村民完成农田工作 | 已验证 | 农田结果表、任务执行测试和线上 Worker 运行日志；玩家种植/收获经济仍未实现 |
| 至少两个村民有不同风格对话 | 服务端回退已具备 | 确定性说话风格测试、双 NPC 对话关系/记忆事件和线上运行记录；模型驱动风格差异仍待真实客户端验收 |
| 河道、阻挡物、楼梯和道具规则 | 首版已具备 | 导航/高度/库存测试、线上 `bridge_kit` 跨水道任务；真实素材在后台修改后的画面重规划仍未验收 |
| 地图变化后旧路线失效 | 服务端已具备 | 导航版本、主动重规划事件、路线高度复核和线上重规划切片；最终画面仍待验收 |
| Worker Docker 启停、租约和无模型回退 | 已具备 | `v23` 健康接口、租约接管/回滚测试、线上超时与非法计划回退记录 |
| WebSocket 多用户/NPC 实时显示 | 代码已具备，人工未验收 | 客户端桥接、快照/增量和单调序列测试；双账号、五人同屏、断线重连仍按用户要求最后执行 |
| 关系衰减、玩家经济、内容审核分类、桥梁建造队列 | 暂停等待用户规则 | 这些会改变线上关系、钱包、审核结果或地图玩法；不能用默认值代替用户选择 |

因此当前没有发现可以在不改变用户规则的前提下继续扩展的必需第一版代码缺口。下一次推进顺序为：先实现用户确认的四项规则并进行针对性测试，再完成管理员真实导航编辑回读，最后执行双账号和五人同屏人工验收。

### 18.66 审计后回归复核（2026-11-12）

方案审计后再次执行当前重点回归：`DeskBox.NpcWorker.Tests` 17/17 通过；11 个共享小镇服务测试全部通过，包括 Agent 可观测性、模型配置/成本、跨高度、库存预约、导航、社交、任务、玩家位置、农田和工作结果。关键断言保持不变：导航测试 25 项、跨高度 10 项、库存预约 12 项、任务执行 15 项、玩家位置 53 项。

本轮没有修改应用代码、数据库、NPC、模型配置、地图或线上 Worker。四项待用户选择的规则仍未写入默认行为；双账号实时画面仍是最终验收项。

### 18.67 实时链路最终验收前自动化复核（2026-11-12）

在等待业务规则选择期间，重新复核实时链路的自动化覆盖：`TownRealtimeTests` 当前覆盖未授权连接拒绝、分片消息、瞬时/持久玩家位置、NPC 路线和对话广播、重连换令牌并重新获取快照、初始握手重试、非法移动隔离以及 8 个连接接收 24 次广播；Worker 测试 17/17 通过。客户端脚本 `verify-town-realtime.mjs` 通过玩家/NPC 增量单调序列、重复事件丢弃、像素位置和过期 presence 检查；`town.js`、`town-realtime.js` Node 语法检查通过。

这些检查证明实时链路的协议和防回退逻辑保持可用，但不替代两个真实账号同时登录、五人同屏、断线重连和 NPC 逐格动画的最终画面验收。

### 18.68 等待业务规则期间 Worker 健康采样（2026-11-12）

在没有启用村民、没有切换模型和没有写入数据库的情况下，对线上 Worker 做三次、间隔 20 秒的采样：三次均为 `deskbox-npc-worker:v23`、`running`、`restart=0`；健康接口三次均返回 HTTP 200，实时指标保持 `activeConnections=0`、`acceptedConnections=0`、`broadcasts=18`、`droppedConnections=0`。采样窗口最近 2 分钟日志没有 `error`、`exception`、`action_failed`、`no_route` 或 HTTP 500。

这次观察确认等待用户规则期间服务稳定，没有因为暂停 NPC 或模型配置而产生新的运行错误；没有把无客户端连接误记为实时画面验收通过。

### 18.69 用户选择“继续只做不依赖规则的验收”后的回归（2026-11-12）

收到用户确认后执行了完整的自动化回归和线上只读检查：

- `DeskBox.NpcWorker.Tests`：17/17 通过；
- `DeskBox.Tests`：5350/5350 通过，x64 平台参数符合仓库要求；
- PHP 服务端测试：38 个实际执行通过，2 个 MySQL DSN 测试按预期跳过，无失败；
- `verify-town-realtime.mjs`、`town.js`、`town-realtime.js` 检查通过；
- 线上 `deskbox-npc-worker:v23`：`running`、重启 0、健康接口 200，`activeConnections=0`、`droppedConnections=0`、`broadcasts=18`。

这次没有写入四项业务规则，也没有改变 NPC、地图、模型、钱包或 Worker 配置。自动化回归继续通过；剩余线上人工项仍是管理员登录态导航编辑回读，以及最后的双账号/五人同屏/断线重连画面验收。

### 18.70 用户确认的第一版范围更新（2026-11-12，当前权威口径）

本节由用户明确确认，优先级高于本文件前面已经过时的“后续再做”“等待用户选择”和旧进度表。后续开发、测试和发布必须先按本节执行。

| 第一版必须完成事项 | 具体要求 | 验收证据 |
| --- | --- | --- |
| SltBox 账号与玩家角色 | 登录身份直接关联共享小镇；每个账号有自己的玩家角色、位置、外观/身份和装饰库存；玩家可以部署、移动、删除自己的装饰，不能操作其他账号的私有装饰 | 两个账号分别登录后，角色、位置、装饰和权限互不串号；服务端拒绝跨账号对象操作 |
| NPC 经济 | 玩家暂不做经济、钱包、购买和领取；NPC 在 Web 端可初始化余额和库存；NPC 可相互交易买卖；余额不足时通过工作、服务、生产或赚钱任务获得收入；所有交易和收入有账本 | 后台初始化两名 NPC 余额/库存；完成一次买卖；余额不足时生成赚钱任务；交易双方余额、库存和审计记录一致 |
| 环境识别与空间记忆 | NPC 根据素材 ID、素材名称、分类、导航语义和坐标识别房屋、商店、农田、工坊及重要装饰；把观察结果、地点别名和坐标写入记忆；素材变化后旧记忆过期或待确认 | NPC 能回答并前往“商店/某人的家/农田”等地点；替换或移动素材后旧坐标不再直接作为可靠目标 |
| NPC 个人住房 | 每个 NPC 配置家的素材 ID、素材名称快照、坐标和休息点；工作完成后回家休息；回家失败记录阻挡原因并重新决策 | 每个样例 NPC 都能从工作地回到自己的家；改变回家路线后能进入等待/重规划并保留事件记录 |
| 模型调用频次 | Web 端可按 NPC 或全局设置决策间隔、观察间隔、对话冷却和每小时最大决策次数；Worker/服务端共同限频，禁止每 tick 调用 | 设置 60 秒间隔后，连续观察窗口内不会超频；对话冷却生效；模型暂停后确定性日程仍运行 |
| 面对面对话 | 由发起者根据记忆中的目标坐标寻路；双方到交互距离并面向后才进入对话；对话文本、双方坐标、地点、发起者、目标和地图版本写入双方记忆 | 发起者实际移动到对方身前再出现气泡；双方记忆包含同一轮对话和坐标；不能远距离“瞬间对话” |
| 找不到对方后的重决策 | 对方回家、外出或移动后找不到时，写入目标缺失记忆、最后已知坐标和原因；然后重新进入 Agent 决策，不把失败记为成功 | 构造目标离开场景，验证 `conversation_target_missing`、记忆落库和下一步动作均存在 |

#### 本版明确暂缓

以下内容从当前第一版开发和验收中移出，保留现有必要运行字段即可，不继续扩展：

- Token 使用量报表，尤其是费用金额、单价、币种、成本快照和供应商价格历史；当前使用包月模型，无法据 Token 数可靠判断费用。
- 后台“测试模型连接”以及其他用于验证模型 API 是否正常的专用按钮或报表。模型失败只按运行日志记录并回退确定性日程。
- 玩家经济、玩家钱包、玩家购买、玩家领取和玩家交易。

暂缓不等于删除已有日志字段或回滚已有数据库；后续若模型计费方式改变，再单独立项恢复费用运营能力。NPC 调用频次控制不属于费用报表，仍属于第一版必须完成的行为稳定性要求。

#### 当前开发顺序

1. 先完成账号/玩家装饰边界、NPC 家和 NPC 经济基础数据与后台配置。
2. 再完成素材名称/坐标观察记忆、地点记忆失效和模型调用频次策略。
3. 然后完成面对面对话、找不到对方记忆和下一步重决策。
4. 对上述规则完成自动化测试与单账号线上验证后，再执行两个账号、五人同屏、断线重连和最终实时画面验收。
5. Token 费用报表、模型连接测试和玩家经济不应插入以上顺序。

### 18.71 本次需求到第一版交付物的索引（2026-11-12）

为避免后续上下文丢失，下面把本次确认的要求映射到必须交付的功能和验收证据。除“明确暂缓”外，均属于第一版完成条件：

| 编号 | 本次确认要求 | 第一版必须交付 | 最小验收证据 |
| --- | --- | --- | --- |
| 1 | SltBox 账号关联；每个用户有自己的玩家；可自行部署装饰 | 复用登录会话建立玩家角色、位置、外观和装饰所有权；服务端按 `user_id` 校验放置、移动、删除 | 两个账号登录后角色/位置/装饰不串号；跨账号对象操作返回权限错误 |
| 2 | 暂停 Token 报表、费用统计和模型 API 正常性验证 | 保留运行状态、错误、必要 Token 和耗时字段用于排查；删除本版费用、单价、币种、成本快照和连接测试验收项 | 模型失败有日志并回退日程；后台没有把包月 Token 换算成费用 |
| 3 | 玩家暂不做经济；NPC 可初始化、交易，没钱能赚钱 | Web 配置 NPC 初始余额/库存；交易、收入、支出写入账本；余额不足时生成合法赚钱任务，不产生负余额 | 两个 NPC 完成一次交易；余额不足后完成赚钱任务，钱包/库存/账本一致 |
| 4 | NPC 按素材名称识别房屋、装饰等，并记住坐标和用途 | 服务器生成带素材 ID、名称、分类、导航语义和坐标的观察结果；地点别名和过期记忆可查询 | NPC 能定位商店、农田、工坊和房屋；素材替换/移动后旧地点记忆失效或待确认 |
| 5 | 每个 NPC 有家，工作后回家休息 | Web 配置家的素材 ID、名称快照、坐标和休息点；工作/外出完成后生成回家任务；失败记录阻挡原因 | 每个样例 NPC 能从工作地回家；回家路线被阻挡时进入等待/重规划并留有事件 |
| 6 | 模型调用频次可在 Web 后台控制 | 增加全局/NPC 决策间隔、观察间隔、对话冷却和每小时上限；Worker 与服务端共同限频 | 设置冷却后观察窗口内不超频；暂停模型后确定性日程仍运行 |
| 7 | 面对面对话；发起者按记忆坐标去找人；找不到要记忆并重决策 | 发起者寻路、交互距离、朝向校验；双方写入对话内容/坐标/地点/地图版本；目标缺失写记忆并触发下一次决策 | 对话气泡只在双方到位后出现；目标离开后产生 `conversation_target_missing` 和下一步动作 |

#### 本次范围的排除项

- Token 使用量报表，尤其是费用金额、单价、币种、供应商价格历史和成本预警。
- 后台“测试模型连接”或其他用于证明 API 正常的专用按钮。
- 玩家经济、玩家钱包、玩家购买、玩家领取和玩家交易。

这些项目可以保留已有数据库字段和运行日志，但不得被旧章节的历史实现或后台入口重新列为第一版完成条件。NPC 的调用频次限制属于行为稳定性，仍必须在第一版完成。

### 18.72 第一版闭环实现进度（2026-11-12，本轮更新）

本节是 18.70/18.71 之后的实现记录。它把本轮代码、迁移和测试映射到上面的七项范围，后续恢复工作时应先阅读本节，避免把历史章节中已经废止的费用范围重新加入第一版。

| 必须完成项 | 当前状态 | 已实现内容和代码位置 | 仍需完成的验收 |
| --- | --- | --- | --- |
| SltBox 账号与玩家角色 | 已有服务端基础，线上画面验收未完成 | `api/v2/town/world.php` 在登录会话进入小镇时按 `user_id` 创建/读取 `town_player_characters`；玩家位置、外观和装饰权限沿用共享小镇的账号所有权校验。 | 两个真实 SltBox 账号同时登录，确认角色位置、外观、装饰互不串号，并验证跨账号放置/移动/删除均被拒绝。 |
| 玩家装饰权限 | 已实现服务端链路，人工验收未完成 | 玩家对象写入仍带 `owner_user_id`；共享素材编辑、对象移动、删除和实时广播不会把 NPC 或其他玩家对象当成当前账号对象。 | 用两个账号在同一地图实际放置、移动、删除各一个装饰，检查 WebSocket 和刷新后的结果。 |
| NPC 经济 | 已实现基础闭环 | `api/_app/town_npc_life.php` 提供余额、库存、收入和账本操作；`api/v2/town/npcs/trade.php` 提供交易；工作完成会按职业/工作类型产生收入；余额不足时只能通过已授权的工作/服务/生产结果增加余额。 | 后台真实初始化两名 NPC 的余额/库存，完成一次交易并让一名 NPC 通过赚钱工作恢复余额；补线上交易审计回读。玩家经济仍不开发。 |
| 环境识别与空间记忆 | 本轮已补齐服务端实现和自动化测试 | `town_npc_observe_environment_if_due()` 按 NPC 观察间隔读取附近素材 ID、名称、分类、导航语义和坐标，并记录工作点、NPC 家和附近 NPC；记忆字段包括 `subject_npc_id`、`asset_id`、`asset_name`、`tile_x`、`tile_y`、`navigation_version`。地图导航版本改变时旧 `environment_observation` 记忆过期。 | 线上替换/移动房屋、商店或装饰后，确认下一次观察得到新坐标，旧坐标不会作为可靠目标。 |
| NPC 个人住房与回家 | 已实现服务端基础闭环 | `town_npc_homes` 保存每个 NPC 的家素材 ID/名称、坐标和交互点；工作任务完成后 `town_npc_queue_home_rest()` 自动创建回家路线和休息任务；无路可回时写入 `home_blocked` 事件与活动日志。 | 给 5 个样例 NPC 配置不同家庭，线上让工作完成并观察回家；改变阻挡物后确认进入等待/重规划。 |
| 模型调用频次 | 已实现 Worker 与服务端策略 | `town_npc_decision_policies` 保存决策间隔、观察间隔、对话冷却和每小时上限；后台可按 NPC 配置；Worker 的 `CanScheduleDecision()` 与服务端对话冷却共同限频；观察函数不会每 tick 重复写入。 | 在线设置 60 秒/5 分钟策略后观察一小时内调用次数；模型暂停或不可用时确认确定性日程继续运行。 |
| 面对面对话 | 本轮已实现并覆盖自动化测试 | `town_npc_find_talk_route()` 只规划到目标四邻域中的可达交互格；任务 payload 保存 `target_npc_id`、`known_target`、`target_tile`、`approach_tile`、`facing` 和导航版本；到达时重新校验目标位置、可用状态和曼哈顿距离，并为双方写入朝向。 | WebSocket 画面验收：发起者先走到对方面前，双方相邻且面向后才显示对话气泡，不能远距离完成对话。 |
| 对话空间记忆 | 本轮已实现并覆盖自动化测试 | `town_npc_record_talk_result()` 为双方写入同一段对话、对方 ID、地点坐标、附近素材 ID/名称和导航版本，同时更新双向关系和事件。 | 在线确认双方记忆查询显示相同文本、地点和地图版本。 |
| 找不到对方后的重决策 | 本轮已实现并覆盖自动化测试 | 目标在规划期间不可用、无相邻可达格，或到达时已回家/外出/移动，均写入 `conversation_target_missing`、最后已知坐标、原因和事件，并将任务标记失败；Worker 下一轮会重新进入确定性/模型决策。 | 在线让目标在行走途中离开，确认失败日志、记忆和下一步动作均出现，且不会把失败写成成功对话。 |
| Token/费用报表和 API 测试 | 按用户要求暂缓 | 保留运行日志中的必要输入/输出/总 Token、耗时和错误，继续支持模型失败回退；不新增费用换算、单价、币种、成本报表或“测试连接”验收。 | 当前无需验收；模型包月期间不得把 Token 换算成费用。 |

#### 本轮新增或修改的文件

- `server/deskbox-api/api/_app/town_npcs.php`：空间观察、动态记忆字段、面对面路线、朝向、目标可用性校验、目标缺失记忆、双向空间对话记忆和工作后回家衔接。
- `server/deskbox-api/api/_app/town_npc_life.php`：滚动迁移兼容的表/列探测，保证旧数据库在新字段尚未执行时仍可读。
- `server/deskbox-api/database/040_shared_town_npc_conversation_v1.sql`：为运行状态增加 `facing` 字段。
- `src/DeskBox.NpcWorker/Program.cs`：从 `agent_context.memories` 提取目标最近空间记忆，作为 `known_target` 发送给服务端；服务端仍是坐标和通行的最终裁决者。
- `server/deskbox-api/tests/town_npc_social_test.php`：覆盖相邻交互格、双方朝向、空间对话记忆和目标移动后的失败记忆。
- `server/deskbox-api/tests/town_npc_spatial_memory_test.php`：覆盖素材/工作点/家庭观察字段和导航版本变化后的记忆失效。
- `tests/DeskBox.NpcWorker.Tests/NpcWorkerSchedulingTests.cs`：覆盖 Worker 从空间记忆携带 `known_target`。

#### 本轮验证记录

以下命令在本地工作区通过：

```text
php server/deskbox-api/tests/town_npc_social_test.php                 # checks ok
php server/deskbox-api/tests/town_npc_spatial_memory_test.php         # checks ok
php server/deskbox-api/tests/town_npc_life_test.php                   # 12 checks ok
php server/deskbox-api/tests/town_npc_navigation_test.php             # 25 checks ok
php server/deskbox-api/tests/town_npc_inventory_reservation_test.php  # 12 checks ok
dotnet test tests/DeskBox.NpcWorker.Tests/DeskBox.NpcWorker.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64  # 18 passed
dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore -v:minimal                              # 0 warnings, 0 errors
```

自动化通过只证明本地 PHP/Worker 行为和数据边界；它不替代远程迁移执行、线上 Web 管理后台回读、双账号登录、五人同屏、断线重连和真实地图素材替换验收。当前第一版总状态仍为“进行中”，下一步优先顺序是：执行/核对 `040` 线上迁移，补齐样例 NPC 家庭与导航素材，完成单账号在线观察，再做双账号和五人同屏的最后画面验收。

### 18.73 线上受控面对面对话验收与状态恢复（2026-11-13）

本轮完成了第一版“面对面对话”线上服务端闭环验收。验收使用站点 PHP 8.2 CLI 运行时加载线上同一套 `town_npcs.php`、`town_navigation.php` 和 `shared_town.php`，没有绕过服务端直接写入对话结果。

| 验收步骤 | 结果 | 证据 |
| --- | --- | --- |
| 临时恢复 5 个样例 NPC | 已完成 | 线上 `town_npcs.agent_paused=0`，5 个 NPC 均可被 Worker 调度；验收结束后已全部恢复为 `agent_paused=1` |
| 发起面对面交谈 | 已完成 | 林野 `(113,96)`，目标周岩 `(112,96)`；服务端把交互点规划为 `(113,96)`，保存 `target_tile`、`approach_tile`、`facing=left`、`navigation_version=3` |
| 到达后再次校验 | 已完成 | `town_npc_advance_task()` 第 2 次推进返回 `task_status=completed`；完成前要求目标仍可用、位置未变且双方曼哈顿距离不超过 1 |
| 双方朝向 | 已完成 | 林野 `facing=left`，周岩 `facing=right`；两人相邻且互相面对 |
| 双向空间记忆 | 已完成 | 双方各有一条 `memory_type=conversation`，内容、对方 NPC、地点 `(113,96)` 和 `navigation_version=3` 一致 |
| 对话事件 | 已完成 | `conversation` 事件含任务 ID、对话文本、目标坐标、交互坐标、目标 NPC 和导航版本；双方关系同步增加 1 点好感 |
| 目标离开时的失败分支 | 已有线上证据 | 前一轮目标在路线执行期间离开，任务以 `conversation_target_missing` 失败，并写入最后已知坐标；该失败不会被记录为成功对话 |
| 验收后恢复 | 已完成 | 5 个 NPC 均为 `agent_paused=1`、`state=rest`、`current_task_id=NULL`；位置保留为当前线上位置 |
| Worker 恢复 | 已完成 | `deskbox-npc-worker:v24` 为 `running`、`restart=0`；`/health` 返回 200，`ok=true` |

本次成功对话是在短暂停止 Worker 后冻结目标位置完成的，目的是消除验收期间目标自行进入日程任务的竞争；停止期间由同一 PHP 服务函数逐格推进任务，完成后已启动 Worker。该证据证明服务器路线、相邻交互、朝向、双向记忆和失败分支正确，但不等同于两个真实客户端的 WebSocket 画面验收。

#### 当前第一版交付状态（本轮权威更新）

| 范围 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| SltBox 账号关联、玩家角色和玩家装饰权限 | 服务端已具备 | 两个真实账号同时登录的串号/跨账号权限画面验收 |
| NPC 家庭、回家休息、环境观察和空间记忆 | 服务端闭环已具备，线上样例已初始化 | 线上替换素材后观察记忆失效与重新识别的人工回读 |
| NPC 余额、库存、交易和工作收入 | 基础闭环已具备 | 后台实际初始化两名 NPC 并完成一次交易的线上回读 |
| 模型调用频次控制 | Worker 与服务端策略已具备 | 后台设置频次后进行窗口统计验收 |
| 面对面对话、双向记忆、朝向和目标缺失 | 服务端闭环已验收 | 两个真实客户端中逐格移动和气泡显示 |
| WebSocket 玩家/NPC 实时同步 | 代码和自动化回归已具备 | 双账号、五人同屏、断线重连的最终人工验收 |
| Token 费用报表、费用换算、模型连接测试 | 按用户要求暂缓 | 不属于当前第一版验收 |
| 玩家经济 | 按用户要求暂缓 | 不属于当前第一版验收 |

后续顺序保持为：先做账号/装饰、NPC 交易、环境记忆和频次策略的线上人工回读，再执行双账号、五人同屏、断线重连和最终实时画面验收。费用相关报表、费用换算、模型 API 测试按钮和玩家经济不得重新插入当前第一版范围。

### 18.74 线上 NPC 交易与调用频次受控验收（2026-11-13）

本轮补齐了不需要双账号画面操作的两项线上验收，所有临时状态均已恢复：

| 项目 | 验收过程 | 结果 |
| --- | --- | --- |
| NPC 交易原子性 | 林野以 `seed=1`、金额 10 向周岩出售；随后周岩以同样数量和金额反向归还 | 新增 2 条 `town_npc_transactions`，同时产生 `trade_income`/`trade_expense` 账本记录；交易前后林野余额均为 130、种子均为 20，周岩余额均为 100、种子均为 0 |
| 交易对象和库存校验 | 两次交易均经过 `town_npc_trade()` 的启用状态、卖方库存、买方余额和事务锁校验 | 两次交易均成功提交，没有负余额或库存丢失；线上交易总数为 2，钱包账本总数为 10 |
| 观察频次限制 | 临时把林野观察间隔改为 5 秒：首次观察写入 16 条记忆；4 秒内重复调用保持 49 条；超过间隔后增加到 65 条 | 证明服务端按 `observation_interval_seconds` 抑制重复观察，不会每 tick 无限写入 |
| 状态恢复 | 恢复林野原策略 `decision=120s`、`observation=300s`、`dialogue cooldown=180s`、`max decisions/hour=30`，并重新暂停林野 | 5 个启用 NPC 均为 `agent_paused=1`；Worker v24 仍 `running`、重启 0，健康检查 200 |

这次交易使用“正向交易 + 反向归还”保持线上钱包和库存不被验收污染；交易和账本记录作为可审计历史保留。该验证证明服务端经济事务和观察限频已在线运行，但没有把 NPC 交易误认为玩家经济，玩家经济仍按 18.70 明确暂缓。

#### 阶段进度更新

| 阶段 | 当前结论 | 仍需完成 |
| --- | --- | --- |
| 账号/玩家装饰边界 | 服务端基础已具备 | 两个真实 SltBox 账号同时登录，验证角色、装饰所有权和跨账号拒绝 |
| NPC 家与空间记忆 | 服务端和样例数据已具备 | 在线替换素材后的记忆失效/重新识别回读 |
| NPC 经济 | 线上交易、钱包、库存、收入账本已验证 | 后台页面实际初始化和交易操作回读 |
| 模型调用频次 | 服务端观察限频已验证，策略已恢复 | Worker 决策间隔、每小时上限和对话冷却的完整窗口统计 |
| 面对面对话 | 线上服务端闭环已验证 | 两个真实客户端的逐格移动、朝向和气泡画面 |
| 最终实时验收 | 尚未开始 | 双账号、五人同屏、断线重连和素材修改后的画面重规划 |

后续可继续推进管理员登录态回读和单账号频次窗口统计；涉及两个真实账号的操作时再暂停请求你的判断或登录配合。

### 18.75 线上账号角色与装饰所有权只读核对（2026-11-13）

线上数据库当前有 2 个 `town_player_characters` 记录，分别对应两个不同的 SltBox `user_id`；未设置外观素材时使用空外观 ID。未删除的 `town_objects` 中，有 22 个属于第一个用户、5 个属于第二个用户，未发现跨用户混用的所有权记录。

这项核对证明账号角色和对象所有权已经落到线上数据模型，但仍不能替代两个真实客户端同时操作。最终账号验收仍需在两个登录会话中各放置/移动/删除一个装饰，并确认另一个账号只能看到结果、不能修改对象；该批次按方案留到实时画面验收阶段。

### 18.76 交易失败原子性修复与 Worker 恢复（2026-11-13）

前一轮受控交易验收后，发现 `town_npc_advance_task()` 在交易已经修改库存/钱包、但账本写入或其他数据库操作抛错时，会直接把任务标记为失败并提交外层事务，存在部分交易被保存的风险。本轮已修复并完成部署：

- `town_npc_validate_plan_envelope()` 现在即使模型没有提供可选的 `steps` 数组，也会无条件校验 `trade.item_id`、`trade.quantity` 和 `trade.amount`；非法交易不会进入执行阶段。
- 交易执行前建立任务级 `SAVEPOINT`，交易失败时回滚到保存点，再记录 `trade_failed`/具体失败原因并提交任务失败状态；库存、钱包、交易记录和账本不会留下半笔数据。
- 新增线上等价的 SQLite 故障注入测试：在第二笔账本写入处主动失败，确认任务失败、余额和库存保持不变、不会留下交易记录；交易成功、余额不足和无 `steps` 交易校验也继续通过。
- 本地验证：`town_npc_social_test.php`、`town_npc_life_test.php`、Worker 测试 20/20 通过；`town_npcs.php` PHP 语法检查通过。
- 线上 `town_npcs.php` 先备份到 `/root/deskbox-backups/trade-savepoint-20261113-091708/`，服务器 PHP 8.2 语法检查通过；远程 SHA-256 与本地一致：`304a733a8a3f9e41748cc5ba5c552225f8202e0c9deca6709ecf750dd1b37c36`。
- 之前为受控交易暂时停止的 `deskbox-npc-worker:v25` 已重新启动；当前容器 `running`、重启次数为 0，`/health` 返回 HTTP 200。线上交易验收使用的 5 个 NPC 暂停状态和钱包/库存恢复策略保持不变，未把临时验收状态当作长期启用配置。

这次修复完成了交易失败的服务端数据边界，但不把手动调用 `town_npc_trade()` 等同于模型自主买卖。模型决定交易、余额不足后生成赚钱任务、长路线目标预留，以及两个真实账号/五人同屏的最终画面验收仍属于当前第一版剩余工作。

### 18.77 五个 NPC Worker 短时运行验收（2026-11-13）

按用户授权，临时保存 5 个样例 NPC 的原始 `agent_paused`/`enabled` 状态，将全部 5 个启用 NPC 恢复运行约 20 秒，再使用同一份备份恢复原状态。线上没有修改长期配置：

- 运行期间数据库统计为 `total=5、active=5`；Worker 使用 `deskbox-npc-worker:v25`，容器保持 `running`、重启次数为 0。
- 运行期间健康接口返回 `ok=true`，实时广播计数从启动后的初始值继续增加到 15；最近 25 秒日志未发现 `error`、`exception`、`fatal`、HTTP 500 或 `lease_required`。
- 验收结束后从服务器备份 `/root/deskbox-backups/five-npc-runtime-acceptance-20261113-092128/npc-state.json` 恢复，数据库统计为 `total=5、active=0`；Worker 继续运行且健康接口仍为 HTTP 200。

这证明 5 个样例 NPC 可以同时被 Worker 接管并保持服务稳定，但 20 秒窗口短于默认决策间隔，不能作为模型自主行动、五人同屏画面或跨账号实时同步的最终验收。后续仍需在不改变长期暂停策略的前提下，单独安排可观察的模型/确定性动作窗口和真实客户端画面验收。

### 18.78 交易日程 payload 发布与 Worker v26（2026-11-13）

本轮把确定性交易日程补齐并发布到线上。所有线上写入均先备份，NPC 的长期启用/暂停状态没有被部署动作改变。

#### 本轮实现

| 范围 | 已完成内容 | 代码/数据位置 |
| --- | --- | --- |
| 日程交易参数 | `town_npc_schedules` 可选保存 `payload_json`；后台“交易”动作可填写商品 ID、数量、金额、卖方和买方；兼容迁移前没有该列的旧数据库。 | `database/041_shared_town_npc_schedule_payload.sql`、`api/_app/town_npcs.php`、`api/admin/views/town_npcs.php` |
| 确定性 Worker | `DeterministicSchedule.Next()` 读取扁平 payload 或嵌套 `trade` payload，生成带 `target_npc_id`、卖方/买方、商品、数量和金额的 `trade` 行动；缺省值不会绕过服务器校验。 | `src/DeskBox.NpcWorker/Program.cs` |
| 服务端安全边界 | 交易行动即使没有 `steps` 也必须校验商品、数量和金额；路线、目标可用性、库存、余额、保存点和最终提交仍由 PHP 服务端决定。 | `api/_app/town_npcs.php` |
| 测试回归 | 修复交易日程测试中的未定义 `$otherNpc` 警告，测试明确使用第二个 NPC ID。 | `tests/town_npc_task_execution_test.php` |

#### 线上发布证据

| 项目 | 结果 |
| --- | --- |
| PHP 文件发布 | `api/_app/town_npcs.php` 和 `api/admin/views/town_npcs.php` 先在服务器内备份，再原子替换；使用线上 PHP 8.2 检查均无语法错误。 |
| 远程哈希 | `town_npcs.php`：`cf901122bc13e8a7f0a2d94e81cdbc2fef6e653cf29f9e057e7b42dfa16a6608`；后台视图：`4888678e50992ca718356c94eb51e05e4c4a95c0c3e39cdc35fa2c30ed254c60`，均与本地一致。 |
| 数据库迁移 | 已执行 `041_shared_town_npc_schedule_payload.sql`；远程只读核对 `town_npc_schedules.payload_json` 列计数为 `1`。执行前的表备份位于 `/root/deskbox-backups/schedule-payload-20261113-014712/town_npc_schedules.before.sql`。 |
| PHP 回滚备份 | `/root/deskbox-backups/schedule-payload-20261113-014712/town_npcs.php.before` 和 `town_npcs_view.php.before`。 |
| Worker | 新镜像 `deskbox-npc-worker:v26` 已运行，镜像摘要为 `sha256:5b41b82f03e8ead48ec86fd751927c60515e61a9df52d5544ae62ccb6c77b87c`；`Program.cs` 线上源码哈希与本地均为 `59b345b924d820d81d85760780e2b1058c96301fd0e6386bcb6dc928b620f96b`。`/health` 返回 HTTP 200、`restart_count=0`、实时连接指标正常；旧容器 `deskbox-npc-worker-v25-schedule-payload-rollback` 保留。部署备份目录为 `/root/deskbox-backups/worker-v26-schedule-payload-20261113-015254/`。 |
| 当前样例状态 | 线上只读核对为 `5` 个 NPC、`0` 个启用且未暂停；本轮没有自动恢复或修改长期暂停策略。 |

#### 本地验证

以下测试在发布前全部通过：

```text
php server/deskbox-api/tests/town_npc_social_test.php                 # checks ok
php server/deskbox-api/tests/town_npc_spatial_memory_test.php          # checks ok
php server/deskbox-api/tests/town_npc_life_test.php                    # 12 checks ok
php server/deskbox-api/tests/town_npc_navigation_test.php              # 25 checks ok
php server/deskbox-api/tests/town_npc_inventory_reservation_test.php   # 12 checks ok
php server/deskbox-api/tests/town_npc_task_execution_test.php          # 16 checks ok
dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore -v:minimal # 0 warnings, 0 errors
dotnet test tests/DeskBox.NpcWorker.Tests/DeskBox.NpcWorker.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64 # 21 passed
```

自动化和线上健康检查证明交易日程字段、Worker 解析和部署链路可运行，但还没有把任意 NPC 的长期日程改成交易，也没有把模型自主交易、余额不足后的赚钱决策误判为完成。后续线上验收应先在后台给两名样例 NPC 配置可逆的交易日程，观察交易账本/库存/余额，再恢复原日程和暂停状态。

#### 当前第一版交付表（权威状态）

| 必须完成项 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| SltBox 账号关联、玩家角色和玩家装饰权限 | 服务端和线上数据模型已具备 | 两个真实账号同时登录，分别放置/移动/删除装饰并确认跨账号拒绝；按既定顺序留到最后实时验收。 |
| NPC 环境识别、空间记忆、家庭和回家 | 服务端闭环、迁移和样例数据已具备 | 在线替换/移动房屋、商店或装饰后，人工回读新观察和旧记忆失效；回家被阻挡时的等待画面。 |
| NPC 经济和交易 | 交易服务、账本、库存、保存点回滚和日程 payload 已发布；基础交易自动化和线上手动交易已验证 | 后台实际保存两名 NPC 的交易日程并让 Worker 自主执行一次；余额不足后生成合法赚钱任务的完整线上窗口。 |
| 模型调用频次 | Worker 与服务端决策/观察/对话限频已具备，观察限频已在线验证 | 后台设置频次后做完整决策间隔、每小时上限和对话冷却窗口统计。 |
| 面对面对话、双方记忆和目标缺失 | 服务端线上闭环已验收 | 两个真实客户端中确认发起者走到交互格、双方朝向和气泡显示。 |
| WebSocket 玩家/NPC 实时同步 | Worker v26 和 WebSocket 代码已部署，健康检查正常 | 双账号、五人同屏、断线重连、素材修改后的重规划画面验收。 |
| Token 费用报表、费用换算、模型连接测试 | 按用户要求暂缓 | 不重新加入第一版范围。 |
| 玩家经济 | 按用户要求暂缓 | 不重新加入第一版范围。 |

下一步优先级保持为：先做单账号后台回读（交易日程、频次策略、环境观察），再安排可逆的 NPC 自主交易/赚钱窗口，最后由用户提供两个登录客户端完成 WebSocket、五人同屏和断线重连的画面验收。费用相关报表、费用换算、模型 API 测试按钮和玩家经济仍不属于第一版。

### 18.79 Worker v28 确定性日程去重与线上自主交易验收（2026-11-13）

本轮完成了确定性日程重复执行修复的线上部署，并按用户授权临时恢复 5 个样例 NPC 做一次可逆自主交易验收。验收期间仍没有启用模型调用，使用的是 Worker 的确定性日程回退路径；费用报表、费用换算、模型连接测试和玩家经济继续暂缓。

#### Worker 修复与部署

此前同一确定性日程窗口在任务完成后仍可能再次生成相同任务，交易会重复执行并在库存不足后反复失败。本轮在 `src/DeskBox.NpcWorker/Program.cs` 增加了稳定的日程槽指纹和任务映射：

- 槽指纹包含 UTC 日期、星期、开始/结束分钟和序列化后的槽 payload；管理员修改 payload 会自然生成新的槽身份。
- Worker 记录已完成或失败的槽，在同一窗口不再重复创建任务。
- 任务完成/失败时通过 `task_id -> slot_key` 映射回收状态；租约丢失或 Worker 重启不会把服务器已经完成的任务再次提交。
- 交易任务仍由 PHP 服务器负责路线、相邻交互点、库存、钱包和事务校验，Worker 只提交确定性意图。

本地验证：Worker 测试 `22/22` 通过，`dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore -v:minimal` 为 `0 warnings, 0 errors`。

线上切换证据：

| 项目 | 结果 |
| --- | --- |
| 新镜像 | `deskbox-npc-worker:v28`，镜像摘要 `sha256:7a3ddeecd98d2753ed5ae43d71db477f6bc7fb8b7a7b168e0d177f16ba1d0f11` |
| 主容器 | `deskbox-npc-worker` 为 `running`，`restart_count=0`，健康接口 HTTP 200 |
| 回滚容器 | 旧 v27 保留为 `deskbox-npc-worker-v27-deterministic-slot-rollback`，没有删除旧镜像或容器 |
| 服务配置 | 复用原 `/root/deskbox-npc-worker.env`、`127.0.0.1:28080:8080` 和 `restart=unless-stopped`；没有输出服务令牌 |
| 健康指标 | `activeConnections=0`、`acceptedConnections=0`、`droppedConnections=0`；本次没有真实客户端连接 |

#### 线上自主交易验收

验收备份目录为 `/root/deskbox-backups/autonomous-trade-v28-20261113-025034/`，其中 `before.json` 保存了 5 个 NPC 的启停状态、运行状态、全部日程、钱包和库存。临时配置林野向周岩出售 1 个 `seed`，金额为 10，日程窗口为 UTC 星期五 02:49–02:57；同时临时恢复全部 5 个 NPC 运行。

| 验收项 | 结果 | 证据 |
| --- | --- | --- |
| Worker 自动读取日程 | 已完成 | v28 在窗口内创建 1 个 `trade` 任务，`target_npc_id` 为周岩，`reason=deterministic_schedule` |
| 路线与交互 | 已完成 | 林野从 `(113,96)` 规划到周岩 `(112,96)` 的相邻交互格；任务 payload 含 `target_tile`、`approach_tile`、`facing` 和 `navigation_version=3` |
| 交易提交 | 已完成 | 任务 `a8e8cb7c170bcca4e894e39f47a92d52` 状态为 `completed`；交易记录 `a463c649091ddcbfd4abe0c09b0a2ad8`，`seed=1`、金额 `10` |
| 交易原子性 | 已完成 | 林野余额 `130→140`、种子 `20→19`；周岩余额 `100→90`、种子 `0→1`；没有负余额或库存丢失 |
| 同一窗口重复防护 | 已完成 | 从首次任务完成后继续观察到窗口结束，只有 1 笔新交易，没有第二个相同交易任务；Worker v28 的槽指纹去重生效 |
| 五人运行稳定性 | 已完成 | 5 个 NPC 均被 Worker 调度；其余 NPC 的确定性 `rest` 任务均正常完成，线上没有 HTTP 500、`lease_required` 或 Worker 异常重启 |
| 状态恢复 | 已完成 | 停止 Worker 后恢复备份；5 人均为 `enabled=1、agent_paused=1、state=rest、current_task_id=NULL`，位置回到 `(113,96)`、`(96,96)`、`(112,96)`、`(136,120)`、`(96,96)`；林野原 21 条日程、钱包和库存均恢复 |

交易记录和事件日志作为审计历史保留；临时交易日程、临时任务和余额/库存变化已恢复，不把验收数据留作长期运行配置。该结果证明“确定性日程 → 服务端路线 → NPC 交易 → 槽去重”闭环已在线运行，但不等同于模型自主决策、余额不足后的赚钱决策或两个真实账号的画面验收。

#### 第一版交付表（本轮权威增量）

| 必须完成项 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| NPC 经济和交易 | 线上确定性交易日程已由 Worker 自主执行一次；交易、库存、钱包和服务端原子性均有线上证据 | 模型自主交易、余额不足后选择合法赚钱工作的完整窗口；后台实际配置操作的人工回读 |
| Worker 确定性日程 | 已完成 | 继续观察长时间运行和多 Worker 接管压力；当前仍是单 Worker |
| Worker 线上版本 | v28 已发布，v27 保留回滚 | 线上长时间成本/稳定性观察；不删除 v27 回滚容器，直到最终画面验收结束 |
| 五人 NPC 运行 | 已完成短时调度验收，验收后恢复暂停 | 真实客户端五人同屏、移动动画和对话气泡画面 |
| SltBox 账号/玩家装饰 | 服务端基础和线上数据模型已具备 | 两个真实账号同时登录、跨账号权限与 WebSocket 画面验收 |
| NPC 家、空间记忆、素材替换 | 服务端闭环已具备 | 在线替换/移动房屋、商店或装饰后人工回读新观察和旧记忆失效 |
| 模型调用频次 | 策略和 Worker 限频代码已具备；本轮未启用模型 | 后台设置频次后的决策间隔、每小时上限和对话冷却窗口统计 |
| 面对面对话 | 服务端闭环已验收 | 两个真实客户端逐格移动、朝向和气泡画面 |
| WebSocket 实时同步 | 代码、Worker 广播和健康指标已部署 | 双账号、五人同屏、断线重连、素材修改后的重规划画面 |
| Token 费用报表、费用换算、模型连接测试 | 按用户要求暂缓 | 不重新加入第一版范围 |
| 玩家经济 | 按用户要求暂缓 | 不重新加入第一版范围 |

下一步仍按既定顺序：先做单账号后台频次和环境记忆回读，再安排模型启用时的限频观察；最后由用户配合双账号 WebSocket、五人同屏、断线重连和素材修改后的画面验收。费用相关工作和玩家经济保持暂缓。

### 18.80 单账号环境记忆和模型调用频次线上验收（2026-11-13）

本轮完成了不需要用户双账号操作的线上单账号验收。验收前停止 v28 Worker，避免临时素材变化被并发任务覆盖；验收结束后恢复全部数据并重新启动 v28。费用报表、费用换算和模型连接测试仍按用户要求暂缓，本轮只验证调用策略、失败回退和环境记忆边界。

#### 环境识别、素材替换和空间记忆

备份目录为 `/root/deskbox-backups/environment-frequency-v28-20261113-030516/`，保存了林野状态、运行状态、原策略、目标素材对象、`town_worlds` 版本和林野原有环境记忆。

临时把林野策略的 `observation_interval_seconds` 设为 5 秒，并通过真实的 `town_npc_snapshot($pdo, true)` 服务路径触发观察：

1. 初次观察记录了附近素材名称、素材 ID 和坐标，例如 `town-source-yidongche / yidongche / (116,94)`，同时记录铁匠铺、道路、房屋、工坊和附近村民。
2. 将对象 `ecd91ad9f6c8731cbc3f3a93bf451d47` 临时替换为 `town-source-fangwu01`，坐标改为 `(118,94)`，调用导航重建使版本 `3→4`。
3. 等待超过 5 秒后再次观察。旧 `yidongche` 记忆的 `expires_at` 被设置为观察时间，导航版本仍为 3；新 `房屋01` 记忆以 `(118,94)` 和导航版本 4 写入，证明旧坐标不会继续作为当前环境目标。

这次测试也保留了同一素材 ID 在地图其他位置的记录，验证失效依据是导航版本和观察内容，而不是简单按素材 ID 全量删除。结束时恢复原对象、导航版本 3、NPC 策略和 166 条原环境记忆。

#### 决策间隔、小时上限和模型失败回退

临时通过后台使用的 `town_npc_save_decision_policy()` 服务函数设置林野策略：决策间隔 5 秒、环境观察 5 秒、对话冷却 60 秒、每小时最多 3 次。仅启用林野，其他样例保持暂停。启动 v28 后观察约 50 秒：

| 验收项 | 结果 | 证据 |
| --- | --- | --- |
| 决策间隔 | 已生效 | 产生的 3 个任务时间为 `03:06:07`、`03:06:33`、`03:06:55`，每次任务完成后才进入下一次决策 |
| 每小时上限 | 已生效 | 查询窗口内恰好 3 个 `town_agent_runs` 和 3 个 `rest` 任务，之后没有第 4 次决策 |
| 无模型/模型异常回退 | 已生效 | 运行记录出现一次 `invalid_plan` 和一次 `timeout`，两次都以 `status=fallback` 完成；服务器仍执行确定性 `rest`，没有阻塞或写入非法行动 |
| 恢复 | 已完成 | 停止 Worker 后恢复林野原策略、原环境记忆、原素材对象和导航版本；临时任务已删除，5 个 NPC 恢复 `agent_paused=1` |

该测试证明后台配置的限频字段能约束 Worker 决策，并且模型超时/非法 JSON 会回退到确定性状态机。记录中的 Token 字段继续保留给运行日志使用，但没有新增费用换算、单价、币种或模型连接测试。

#### 当前第一版交付表（本轮权威增量）

| 必须完成项 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| NPC 环境识别、空间记忆、素材替换 | 已完成一次线上单账号闭环：素材名称、坐标、导航版本和旧记忆失效均有证据 | 真实 Web 管理后台替换素材后的画面回读，以及 NPC 在画面中重新规划路线 |
| 模型调用频次 | 已完成线上策略窗口：5 秒间隔、每小时 3 次上限、异常回退均生效 | 需要在真实后台登录态下保存一次策略并做可见页面回读；模型长期运行统计留到最终阶段 |
| NPC 经济和交易 | 确定性交易已在线验证并恢复 | 模型自主交易、余额不足后赚钱任务的线上窗口 |
| Worker v28 | 运行中，健康 200，重启 0 | 双 Worker 接管和长时间稳定性观察 |
| 账号/玩家装饰、WebSocket | 服务端代码和线上数据已具备 | 必须由用户提供两个真实登录会话完成放置、移动、删除、五人同屏、断线重连和素材修改画面验收 |
| Token 费用报表、费用换算、模型连接测试 | 按用户要求暂缓 | 不重新加入第一版范围 |
| 玩家经济 | 按用户要求暂缓 | 不重新加入第一版范围 |

目前不依赖用户操作的单账号服务端大部分验收已完成，但 NPC 余额不足赚钱任务和后台真实保存回读仍是服务端/管理端缺口。进入双账号 WebSocket 和真实后台画面验收时需要用户配合。

### 18.81 NPC 工作后回家与无路等待线上验收（2026-11-13）

本轮完成了林野“工作 → 自动回家 → 休息”和“家不可达 → 等待/记录原因”的线上服务端验收。验收备份目录为 `/root/deskbox-backups/home-return-v28-20261113-031739/`；测试结束后恢复了 NPC 位置、暂停状态、家庭配置、钱包、库存、任务和工作点预留。

#### 工作后回家

- 临时恢复林野 Agent，使用管理员强制任务入口提交 `work / farm-zone-3 / water_crop`，没有绕过 `town_npc_apply_action()` 的路线和碰撞校验。
- 服务端从 `(113,96)` 规划 40 个格子的路线到三号农田 `(128,120)`，逐格调用 `town_npc_advance_task()`。
- 工作任务完成时自动调用 `town_npc_queue_home_rest()`，创建 `rest / npc-home` 回家任务；林野的家素材为 `town-source-fangwu01（房屋01）`，坐标 `(124,120)`。
- 回家任务路线为 `(128,120) → (127,120) → (126,120) → (125,120) → (124,120)`，到达后状态变为 `rest`，再完成休息任务。

#### 家不可达

为验证等待分支，临时把林野家的目标坐标设为 `(999,999)`，从工作点位置调用同一 `town_npc_queue_home_rest()`；服务端返回空任务并写入：

```json
{
  "event_type": "home_blocked",
  "reason": "no_route",
  "tile_x": 128,
  "tile_y": 120,
  "home_tile_x": 999,
  "home_tile_y": 999,
  "navigation_version": 3
}
```

这证明家路线不可达时不会瞬移、不会伪造休息完成，也不会遗留运行任务；NPC 保持等待状态，下一次决策可重新规划。测试期间产生的临时工作收入账本、任务和工作点预留已删除，日志/事件仅保留必要的审计历史；数据库最终恢复为林野 `(113,96)`、`rest`、暂停，家坐标 `(124,120)`。

#### 当前第一版交付表（本轮权威增量）

| 必须完成项 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| NPC 家庭和回家休息 | 五个样例的住房坐标、素材 ID、可行回家路线和无路 `home_blocked` 分支均已在线事务探针验证 | 仍需让五个样例分别实际完成工作后回家，并在真实画面中观察工作动画、回家移动和阻挡后的等待提示 |
| NPC 环境识别和空间记忆 | 已完成单账号服务端验收 | 后台实际替换素材后的页面回读和画面重规划 |
| 模型调用频次 | 服务函数和 Worker 限频已验证 | 后台登录页面的可见保存回读、对话冷却/全局策略和长时间运行统计 |
| NPC 经济和交易 | 确定性交易和工作收入基础已验证 | 余额不足时自动选择已配置赚钱任务、模型自主交易和余额不足后的完整回路 |
| WebSocket 实时同步 | v28 广播健康正常 | 两账号、五人同屏、断线重连和素材变化的最终画面验收 |

服务端自动验收范围目前已覆盖交易去重、环境记忆失效、决策限频、模型回退、工作后回家和无路等待。仍需补齐 NPC 余额不足时选择已配置赚钱任务的完整闭环，并用真实后台登录态回读住房、库存和限频保存。账号隔离与玩家装饰、NPC 五人同屏、面对面对话气泡、WebSocket 断线重连和素材修改后的视觉重规划仍需要用户提供登录会话。

### 18.82 v28 Worker 全局租约接管与健康采样（2026-11-13）

本轮验证了当前线上 v28 的全局租约互斥和失联接管。测试期间 5 个 NPC 都保持暂停，因此没有业务任务竞争；临时容器结束后已删除，主 Worker 已恢复。

| 步骤 | 结果 | 证据 |
| --- | --- | --- |
| 第二 Worker 竞争租约 | 已完成 | 临时容器 `deskbox-npc-worker-v28-lease-test` 使用 `NPC_WORKER_ID=town-worker-2`；主 Worker 持有租约时，第二 Worker 的租约请求持续返回 409，不执行写入 |
| 主 Worker 失联 | 已完成 | 停止 `deskbox-npc-worker` 后等待超过 30 秒 TTL，第二 Worker 健康接口仍为 200，并成功取得 `main` 租约 |
| 租约接管回读 | 已完成 | `town_agent_leases.main.holder_id=town-worker-2`，随后停止临时 Worker 并等待 TTL 到期 |
| 主 Worker 恢复 | 已完成 | `town-worker-1` 重新取得租约；v28 容器 `running`、`restart_count=0`、健康 HTTP 200 |
| 只读健康采样 | 已完成 | 连续 3 次间隔 8 秒返回 200，实时指标无连接错误；采样日志没有 `error`、`exception`、`fatal`、HTTP 500 或 `lease_required` |

该证据覆盖“同一时间只有一个 Worker 推进共享村民”和“持有者失联后可以接管”的服务端部署行为；仍不等同于两个真实客户端的画面验收，也没有在租约测试期间启用 NPC。

#### 当前第一版交付表（本轮权威增量）

| 必须完成项 | 当前状态 | 还缺什么 |
| --- | --- | --- |
| Worker 全局租约 | v28 已完成竞争、TTL 到期接管和恢复 | 长时间双 Worker 压力测试；最终运行仍建议保持单 Worker |
| Worker 健康和回退 | v28 健康 200、重启 0，模型异常可回退 | 长时间运行观察；模型费用相关按要求暂缓 |
| NPC 家庭/环境/频次 | 服务端线上验收完成并恢复 | 真实后台页面和客户端动画回读 |
| 账号隔离、玩家装饰、WebSocket | 代码与线上服务已具备 | 必须由用户提供两个登录会话进行最终画面验收 |

当前剩余工作已经集中到需要真实登录画面才能证明的部分。下一步进入最终客户端验收前，我会保持 NPC 暂停和 Worker 单实例运行，不再自行修改长期样例数据。

### 18.83 空环境观察失效修复与线上回归（2026-11-13）

静态审计发现环境观察函数在扫描结果为空时会提前返回，导致素材全部删除后旧的房屋/商店记忆不会按导航版本过期。已修复 `town_npc_observe_environment_if_due()`：现在先按当前 `navigation_version` 过期旧 `environment_observation`，再在没有新观察时返回。

本地验证：

```text
php server/deskbox-api/tests/town_npc_spatial_memory_test.php  # checks ok，包含空观察回归
php -l server/deskbox-api/api/_app/town_npcs.php               # No syntax errors
```

线上发布和回归：

| 项目 | 结果 |
| --- | --- |
| 远程 PHP 发布 | `/www/wwwroot/sltbox.2leaves.cn/api/_app/town_npcs.php` 已替换；PHP 8.2 语法检查通过 |
| 文件一致性 | 远程 SHA-256 `cb3a5c627fd374eee59adf1ae335fef6e4bc8b8bcabdf65b879db330fe0d4d14`，与本地一致 |
| 回滚备份 | `/root/deskbox-backups/environment-empty-observation-20261113-/town_npcs.php.before` |
| 空角落回归 | 临时将林野放到无素材的 `(0,0)`，插入旧版本探针记忆并把导航版本 `3→4`；线上函数返回 `probe_expired=true`、`new_observation_count=0` |
| 状态恢复 | 导航版本恢复为 3，林野恢复原暂停状态和位置，环境记忆恢复为 166 条；v28 Worker 未改配置，重新启动后健康 200、重启 0 |

该修复使“素材被删除或区域完全清空”与“素材被替换/移动”拥有一致的旧记忆失效语义。当前客户端双账号画面验收仍需用户提供两个真实登录会话。

### 18.84 五个样例 NPC 临时恢复验收与状态恢复（2026-11-13）

按授权临时把全部 5 个样例 NPC（林野、花青、周岩、沈禾、顾宁）的 `agent_paused` 从 `1` 改为 `0`，使用线上 `deskbox-npc-worker` 完成短窗口验收；没有改变启用状态、住房、日程或长期配置。验收结束后已将 5 个 NPC 全部恢复为 `enabled=1、agent_paused=1`，当前任务均为空，位置保持原值。

| 验收项 | 结果 | 线上证据 |
| --- | --- | --- |
| 五人快照和 Worker 推进 | 已完成 | Worker 容器运行中；5 个 NPC 均被快照读取，运行状态版本在临时窗口内递增，恢复后不再创建新任务 |
| NPC 交易 | 已完成 | 林野以 1 个 `seed` 向花青交易 1 金额；`town_npc_trade()` 返回交易 ID。探针在数据库事务中回滚，没有改变余额、库存或交易账本 |
| 家和回家路线 | 已完成 | 林野从 `(113,96)` 到自己的 `房屋01` `(124,120)` 返回 45 格可行路线，生成 `rest / npc-home` 回家任务；探针回滚，没有遗留任务 |
| 环境识别与导航版本失效 | 已完成 | 顾宁观察结果写入素材名称、地点和坐标；插入导航版本为 `0` 的旧记忆后，当前版本 `3` 将其过期。探针回滚 |
| 调用频次策略 | 已完成 | 五人线上策略均为决策间隔 120 秒、观察间隔 300 秒、对话冷却 180 秒、每小时最多 30 次；临时窗口内每人 5 分钟内仅 2～3 次运行记录 |
| 非法模型输出回退 | 已完成 | 线上出现 `invalid_plan` 时记录 `status=fallback`，服务器执行确定性 `rest`，没有执行模型返回的非法步骤 |
| 状态恢复 | 已完成 | 5 个 NPC 最终均为 `enabled=1、agent_paused=1`，林野 `(113,96)`、花青 `(96,96)`、周岩 `(112,96)`、沈禾 `(136,120)`、顾宁 `(96,96)`；临时 PHP 探针已从本地和服务器删除 |

这次验收证明服务端交易、住房路线、空间记忆、限频和非法模型回退可以在五人共享状态下运行，但没有替代最终人工画面验收。双账号实时移动、五人同屏、断线重连、面对面对话气泡和真实素材修改后的视觉路线重规划仍保持待验收；Token/费用报表和模型连接测试仍按用户要求暂缓。

### 18.85 五个样例住房路线补验与审查口径修正（2026-11-13）

为避免用林野一个样例代表全部村民，本轮在远程 MySQL 中对 5 个启用样例逐一执行了事务回滚探针：

| NPC | 家素材 | 当前位置 → 家坐标 | 可行路线 | 无路分支 |
| --- | --- | --- | --- | --- |
| 林野 | `town-source-fangwu01` | `(113,96) → (124,120)` | 44 个路线节点 | `home_blocked / no_route` |
| 花青 | `town-source-chaoshi01` | `(96,96) → (92,96)` | 5 个路线节点 | `home_blocked / no_route` |
| 周岩 | `town-source-cunzhangjia` | `(112,96) → (116,96)` | 5 个路线节点 | `home_blocked / no_route` |
| 沈禾 | `town-source-fangwu02` | `(136,120) → (140,120)` | 5 个路线节点 | `home_blocked / no_route` |
| 顾宁 | `town-source-fushipin01` | `(96,96) → (100,96)` | 5 个路线节点 | `home_blocked / no_route` |

所有探针均在事务中回滚，没有改变住房、任务、库存或事件持久状态。该结果把“每个样例的住房配置和路线规则可用”提升为已验证，但仍不等于五个 NPC 都已实际完成工作后回家；实际 Worker 生命周期和画面动画仍待验收。

同时修正本方案的完成口径：NPC 余额不足后选择已配置赚钱任务、后台实际保存和回读住房/库存/限频，以及全局限频策略仍属于第一版待完成或待验收项；不能因为交易、工作收入基础和服务函数探针通过，就把剩余工作概括为只有客户端画面验收。Token/费用报表、模型连接测试和玩家经济继续按用户要求暂缓。

### 18.86 Worker v29 余额不足赚钱回退部署（2026-11-13）

本轮把 Worker 的余额不足回退逻辑发布到线上。模型提出 NPC 作为买方交易且余额为 0 时，Worker 会优先选择当前已配置的赚钱工作槽；如果当前没有赚钱工作槽，则保留交易意图并交给服务端钱包校验，不能绕过余额限制。模型上下文同时包含日程、余额和库存，服务端仍负责路线、库存、钱包和最终执行。

| 项目 | 结果 | 证据 |
| --- | --- | --- |
| 本地 Worker 测试 | 已完成 | `DeskBox.NpcWorker.Tests` 23/23 通过；新增覆盖交易 payload、余额为 0 时优先赚钱、日程槽指纹和空间记忆目标 |
| 本地构建 | 已完成 | `dotnet build src/DeskBox.NpcWorker/DeskBox.NpcWorker.csproj --no-restore -v:minimal`，0 警告、0 错误 |
| 线上镜像 | 已完成 | `deskbox-npc-worker:v29`，远程 Docker 构建成功；v28 容器保留为 `deskbox-npc-worker-v28-broke-fallback` |
| 线上健康 | 已完成 | 主容器 `running`、`restart_count=0`，`/health` HTTP 200；实时连接指标正常 |
| 回滚备份 | 已完成 | `/root/deskbox-backups/worker-v29-broke-fallback-20261113-044608/`，包含切换前容器检查和环境文件 |
| 余额不足完整窗口 | 待验收 | 还需在不改变长期样例数据的前提下配置一次买方余额为 0、存在赚钱工作槽的短窗口，并回读 Worker 生成的工作任务 |

线上版本已更新，但本轮没有修改模型密钥、额度策略、NPC 长期配置、钱包、库存或地图数据。按用户要求，Token/费用报表、费用换算、模型连接测试和玩家经济继续暂缓。下一阶段可进入两个真实登录会话的客户端验收：账号隔离、WebSocket 实时移动、五人 NPC 同屏、断线重连和面对面对话画面。
