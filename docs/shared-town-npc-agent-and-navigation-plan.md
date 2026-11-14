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
| NPC Worker | 已完成 | 独立 .NET Worker、全局租约、确定性回退、任务执行、日志、Docker 运行和健康检查已完成；线上 `deskbox-npc-worker:v32` 正常运行。 | 长时间多 Worker 压力测试不作为当前 UI/素材任务的阻塞项。 |
| 导航与动态通行 | 已完成 | `plain/blocked/stairs/water/bridge`、高度、梯子/桥梁能力、A*、导航版本失效、地图对象变更后重规划已实现；用户已实际确认临时阻挡后 NPC 绕行，临时对象已清理，导航版本保持递增。 | 未配置导航语义的素材默认不可通行。 |
| NPC 家、环境观察和空间记忆 | 已完成基础闭环 | 家的素材 ID/名称/坐标/休息点、工作后回家、阻挡等待、素材名称和坐标观察、导航版本导致记忆过期已实现并有服务端测试。 | 后续若改变记忆模型，必须保留旧坐标失效和来源事件。 |
| NPC 经济 | 已完成基础闭环 | Web 初始化余额/库存、NPC 交易、收入/支出账本、余额不足后的合法赚钱任务已实现；玩家经济仍未实现。 | 继续避免把玩家钱包加入第一版。 |
| NPC-NPC 面对面交谈 | 已完成 | 发起者按目标记忆寻路到相邻格、双方朝向校验、对话和坐标双向记忆、目标缺失事件和下一轮重决策已实现。 |  |
| 玩家面对面交谈 | 已完成并线上验收 | 休息 NPC 才接受邀请；玩家/NPC 必须相邻并面对；对话气泡显示实际台词；同一会话可连续发送，无需解除邀请；两个客户端已确认气泡同步。 |  |
| 居民面板与交互 UI | 已完成主要切片 | 居民详情、资料/聊天分页、定位/取消定位、邀请交谈/跟随、状态上下文栏、素材抽屉、名字/状态开关、聊天输入和气泡已接入。 | 仍需在真实窄屏/桌面窗口做最终视觉回归，不能用静态脚本代替。 |
| 素材自动分析与动态排序 | 房屋拆分已部署待画面验收；批量草稿和连续铺设已完成首批 | PNG Alpha 包围盒、锚点、占地、碰撞、类别、16×16 图集候选区域分析；`y_sort/background/foreground/manual` 动态深度排序；Web 后台自动识别按钮和排序模式已上线。房屋 `split` 模式、`split_y` 保存链路、前景/背景裁剪和前景命中检测已实现并部署；自动识别结果支持最多 500 个候选一次性事务保存为可编辑草稿；桌面编辑模式新增连续铺设笔刷，按网格拖动逐个提交服务器校验。 | 真实房屋素材的墙后/屋檐前效果还需要人工画面验收；自动命名质量和批量确认预览仍可继续优化。 |

### 17.2 关键实现文件

服务端和数据库：

- `server/deskbox-api/api/_app/town_npcs.php`：NPC 行动、家、观察、空间记忆、面对面交谈和目标缺失。
- `server/deskbox-api/api/_app/town_navigation.php`：高度、通行能力、路线和导航版本。
- `server/deskbox-api/api/_app/shared_town.php`：玩家/素材对象权限、变更游标和导航失效。
- `server/deskbox-api/api/_app/town_assets.php`：素材元数据、导航语义和排序模式。
- `server/deskbox-api/api/admin/index.php`、`api/admin/views/`、`api/admin/town-assets.js`、`server/deskbox-api/api/_app/admin_web.php`：Web 管理后台和素材保存调用链。
- `server/deskbox-api/api/admin/town-assets-scan.js`：透明网格识别模块。
- 主要迁移：`028_shared_town_npc_v1.sql`、`029_town_agent_observability.sql`、`034_shared_town_npc_samples_v1.sql`、`037_town_npc_runtime_height.sql`、`039_shared_town_npc_life_v1.sql`、`040_shared_town_npc_conversation_v1.sql`、`041_town_npc_schedule_payload.sql`、`042_town_agent_global_policy.sql`、`043_town_agent_decision_slots.sql`、`044_town_asset_sort_mode.sql`、`045_town_asset_split_occlusion.sql`。
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

- Worker：`deskbox-npc-worker:v32`，健康接口正常，5 个样例 NPC 可运行。
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
- x64 Release 已在独立干净 worktree 按 `Platform=x64`、`RuntimeIdentifier=win-x64`、`SelfContained=false`、`WindowsAppSDKSelfContained=false` 重新生成；对应提交为 `4bd213a1`，发布清单标记 `dirty=false`，共 8339 个文件。产物为 `.artifacts/town-release-clean/src/DeskBox/bin/x64/Release/net10.0-windows10.0.22621.0/win-x64/publish/DeskBox.exe`，SHA-256 为 `E915E794ECCB5C9CAA5BCD6E0C9742D27DCA21336F98EA0482F9C1E0989FEFB4`，并已生成 `release-manifest.json` 和 `SHA256SUMS`。源码分支已推送到 GitHub，但安装器、GitHub Release 附件和 HTTP `stable.json` 尚未发布。

本轮验证（2026-11-14）：

- `dotnet test .\tests\DeskBox.Tests\DeskBox.Tests.csproj --no-restore --verbosity:minimal -p:Platform=x64`：5357/5357 通过，0 失败，0 跳过。
- PHP 语法检查及 NPC 生命周期、社交、任务执行、玩家邀请、空间记忆、居民接口测试全部通过。
- `verify-town-auto-scan.mjs`、`verify-town-sort-mode.mjs`、`verify-town-residents.mjs` 全部通过。
- 使用本地真实浏览器加载 `index.html` 做布局回归：1280×720、720×540、640×480 均无页面横/纵向溢出；编辑模式素材栏、地图和居民面板尺寸均在视口内；640×480 漫游模式素材栏按设计收起为 0 宽；居民面板内部无横向或纵向溢出。截图保存在 `output/playwright/town-ui-1600x900-edit.png`、`output/playwright/town-ui-640-edit-resident.png`。
- canonical Debug 已从 `src/DeskBox/bin/Debug/net10.0-windows10.0.22621.0/DeskBox.exe` 启动；Computer Use 原生管道当前不可用，因此房屋遮挡和窄屏 UI 仍未取得人工画面证据，不能标记为完成。

### 17.4 明确暂缓范围

以下项目按用户要求暂缓，不应重新加入第一版验收：

- Token 使用量报表、费用换算、价格/币种历史和模型连接测试。
- 玩家经济、玩家钱包、玩家购买和玩家领取。
- 关系衰减、敏感内容分类审核。
- 永久桥梁建造队列。
- 真正 3D 导航和复杂 3D 场景。

### 17.5 当前未完成和下一步

按优先级继续：

1. **房屋前后景拆分收口**：实现、后台保存、数据库迁移和线上部署已完成；还需用一个真实完整房屋素材设置 `split_y`，在人物从房屋后方、墙体中段和屋檐前方经过时完成人工画面验收，并记录截图/结果。
2. **素材批量工作流收口**：自动识别结果批量生成草稿、事务保存和地图连续铺设笔刷已完成；后续可优化自动命名预览以及批量确认/撤销，不再阻塞第一版素材编辑。
3. **真实 UI 视觉回归**：浏览器布局基线已覆盖 1280×720、720×540、640×480，并确认素材栏收起、居民面板和页面边界不溢出；仍需在 DeskBox 原生 WebView 中补一次 16:9、窄窗口和不同素材数量的人工画面确认，尤其是气泡和地图上下文栏。
4. **发布范围控制**：源码分支 `codex/town-prototype` 已推送到 GitHub，干净 x64 Release 产物和哈希证据已生成；若要让其他用户使用，还需要按桌面版本发布流程生成/核验正式安装器，上传 GitHub Release 和 HTTP 镜像，并更新 `update/stable.json`。Web/数据库发布不会自动更新桌面程序。

后续每次修改后仍需记录：修改文件、数据库迁移、测试命令、线上备份目录、远程哈希/HTTP 回读，以及未完成的人工画面验收。
