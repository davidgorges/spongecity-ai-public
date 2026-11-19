# SpongeCity AI at a glance

<p align="center">
  <img src="images/sponge-schoolyard.jpg" alt="A schoolyard split in two: sealed asphalt with puddles on the left, rain gardens, permeable pavers, shade trees and a shade sail on the right, with runoff flowing into the gardens" width="100%">
</p>

> Pick a real place. Rebuild it from open and official geodata. Let an AI agent redesign it with
> rain gardens, permeable surfaces and shade, and show what each change does to the water and the
> heat, with every number traceable to its source.

This page is the visual tour: eight diagrams, each with what you need to read it. The full
reference, with every rule and number, is [architecture.md](architecture.md).

**Contents**

1. [System context](#1-system-context): who talks to whom
2. [Inside the application](#2-inside-the-application): the building blocks
3. [Starting a plan](#3-starting-a-plan): from a place name to a 3D site
4. [The import pipeline](#4-the-import-pipeline): six steps, each replaceable
5. [Lifecycles](#5-lifecycles): the two state machines
6. [One chat turn](#6-one-chat-turn): the agent designs through tools
7. [Placing an intervention](#7-placing-an-intervention): the LLM chooses, the server does geometry
8. [Where state lives](#8-where-state-lives): one plan id, several stores
9. [From values to pixels](#9-from-values-to-pixels): the configurator engine

**Three ideas run through all of it:**

| Idea | What it means in the code |
|---|---|
| **Everything is a plan id** | The browser, the agent's tools and the workers run in separate processes and requests. They share nothing but the stores, keyed by the plan's id. |
| **The agent designs, the server decides** | The model chooses *what* goes *where*. Geometry, planning rules, clamping and every number come from PHP, so nothing an agent sends can break a scene or invent a figure. |
| **Every number has a basis** | The impact report, the placement candidates and the simulations carry their assumptions and sources, and the UI shows them. |

---

## 1. System context

The application is one Symfony app plus two Messenger workers. It reaches out to map services,
Hamburg's open data portal, the Anthropic API and, for building simulations, Docker.

```mermaid
flowchart LR
    user(["👤 Planner<br/><small>in the browser</small>"])

    subgraph app["SpongeCity AI · one Symfony 8.2 application"]
        direction TB
        web["<b>Web app</b><br/><small>pages · Live Component<br/>JSON endpoints · /mcp</small>"]
        stores[("<b>Cache pool</b><br/><small>plans and all that<br/>belongs to them</small>")]
        queue[("<b>SQLite</b><br/><small>Messenger queues</small>")]
        workers["<b>Two workers</b><br/><small>async: site imports<br/>simstadt: simulations</small>"]
        web --- stores
        web -- "dispatch" --> queue -- "consume" --> workers
        workers --- stores
    end

    subgraph ext["External services"]
        direction TB
        claude["<b>Anthropic API</b><br/><small>Claude, the planning agent</small>"]
        osm["<b>OpenStreetMap</b><br/><small>Nominatim · Overpass mirrors</small>"]
        hh["<b>Hamburg open data</b><br/><small>OGC API · LoD2/LoD3<br/>DGM1 · 3D Tiles</small>"]
        docker["<b>Docker</b><br/><small>simstadt/simstadt:cli</small>"]
    end

    user == "HTTPS" ==> web
    web <-- "agent turn ⇄ tool calls on /mcp" --> claude
    web -- "geocode" --> osm
    workers -- "map data" --> osm
    workers -- "official layers" --> hh
    workers -- "docker run" --> docker

    classDef person fill:#0f766e,stroke:#0f766e,color:#ffffff
    classDef core fill:#ccfbf1,stroke:#0f766e,color:#042f2e
    classDef worker fill:#e0f2fe,stroke:#0369a1,color:#082f49
    classDef data fill:#fef3c7,stroke:#b45309,color:#451a03
    classDef external fill:#ffffff,stroke:#64748b,color:#0f172a
    class user person
    class web core
    class workers worker
    class stores,queue data
    class claude,osm,hh,docker external
    style app fill:#f0fdfa,stroke:#0f766e,stroke-width:1px,color:#042f2e
    style ext fill:#f8fafc,stroke:#94a3b8,stroke-dasharray:4 3,color:#334155
```

- **The agent's tools are this app's own MCP server.** Claude calls `/mcp` on the same app over
  HTTP while the chat request is still running, so the web server needs more than one worker
  process (the Symfony CLI has several).
- **Slow work never blocks a page.** Site imports (seconds to a minute) and SimStadt runs (up to a
  few minutes) have a queue and a worker each, so one never waits for the other.
- **Every external source can fail on its own.** Overpass is tried mirror by mirror, and each
  Hamburg layer falls back to OpenStreetMap separately. Missing surroundings just mean a generic
  city around the site.

## 2. Inside the application

`src/` is the application; `lib/Core/Configurator/` is the framework-light parametric 3D engine it
builds on. Each layer only calls the layers below it.

```mermaid
block-beta
    columns 6
    L1["① ENTRY POINTS · bind input, then delegate down"]:6
    pages["Controllers<br/>Home · ShowPlan · Chat<br/>Conditions · Surroundings · Terrain"]:2
    live["PlanWorkspace<br/>Live Component"]
    mcp["MCP tools<br/>SpongeCity · Placement · SimStadt"]
    handlers["Message handlers<br/>ImportSite · RunSimulation"]
    cli["Console<br/>app:doctor · app:plan:*"]
    space:6
    L2["② APPLICATION SERVICES · src/SpongeCity · use ③ and ④"]:6
    chat["Chat<br/>PlanAssistant"]
    plan["Plan<br/>SiteEditor · PlanAnalyzer<br/>PlanImport workflow"]
    placement["Placement<br/>Placements · *Siting<br/>RunoffModel · AddHere"]
    import["Import<br/>PlanImporter<br/>six SiteImportSteps"]
    geo["Geo<br/>Overpass · Nominatim<br/>Hamburg layers · Terrain"]
    sim["SimStadt<br/>Runner · Templates<br/>Results"]
    space:6
    L3["③ CONFIGURATOR ENGINE · lib/Core · no framework"]:4
    L4["④ STORES · CacheStore"]:2
    site["Site<br/>SiteType · SiteObject"]
    product["SpongeCityDefinition<br/>InterventionBuilder · SKUs"]
    impact["ImpactCalculator<br/>metrics with bases"]
    scene["SceneSerializer<br/>scene JSON for three.js"]
    stores[("Plan · Conversation · Rationale<br/>Proposal · Terrain · Surroundings<br/>Conditions · Simulation")]:2

    classDef head fill:#0f766e,stroke:#0f766e,color:#ffffff
    classDef entry fill:#ccfbf1,stroke:#0f766e,color:#042f2e
    classDef svc fill:#e0f2fe,stroke:#0369a1,color:#082f49
    classDef eng fill:#ede9fe,stroke:#6d28d9,color:#2e1065
    classDef data fill:#fef3c7,stroke:#b45309,color:#451a03
    class L1,L2,L3,L4 head
    class pages,live,mcp,cli,handlers entry
    class chat,plan,placement,import,geo,sim svc
    class site,product,impact,scene eng
    class stores data
```

The one loop back up: during a chat turn, `PlanAssistant` sends the conversation to Claude, and
Claude calls the **MCP tools** (an entry point) over HTTP before it answers.

| Layer | Rule of thumb |
|---|---|
| **Entry points** stay thin | Controllers bind input (`#[MapRequestPayload]`, `PlanValueResolver` for `Plan $plan`) and delegate. MCP tools are plain services with `#[McpTool]`. |
| **`SiteEditor` is the only writer of a site** | Every change goes through `SiteType::normalize()`: defaults filled in, numbers clamped, unknown fields dropped, objects kept within 100 m. |
| **Extension points are tagged services** | A new import step, map layer, intervention type or SimStadt workflow is one class with `#[AsTaggedItem]`; nothing else changes. |
| **The engine knows no Symfony** | `lib/Core` has no Doctrine and no HTTP; it turns configuration values into parts, prices, metrics and a scene. |

## 3. Starting a plan

A place name becomes a plan in a few hundred milliseconds. The site arrives later, from a worker,
while the plan page polls its status.

```mermaid
sequenceDiagram
    autonumber
    actor U as Planner
    participant H as HomeController
    participant I as PlanImporter
    participant N as Nominatim
    participant S as PlanStore
    participant Q as Queue "async"
    participant W as ImportSiteHandler
    participant P as Plan page

    U->>H: POST / place="Brecht-Schule Hamburg"
    H->>I: start(place)
    I->>N: search
    N-->>I: first hit: lat, lon, label
    I->>S: create plan (status: new)
    rect rgba(15, 118, 110, 0.08)
        note over I,S: under the plan's lock (symfony/lock)
        I->>S: apply "request" → pending
    end
    I->>Q: dispatch ImportSite
    H-->>U: 302 → /plan/{id}
    U->>P: GET /plan/{id}
    loop while pending or importing
        P->>S: poll status (data-poll)
    end
    Q->>W: consume
    W->>S: apply "start" → importing
    W->>W: run the six import steps
    W->>S: site saved, apply "complete" → ready
    P-->>U: the 3D site appears
```

If nothing is found, the controller redirects back home with a flash message. If an import step
throws, the plan moves to `failed` and the page offers a way back. A failed plan can simply be
requested again.

## 4. The import pipeline

<p align="center">
  <img src="images/data-layers.jpg" alt="Exploded stack of data layers for one block: terrain contours, infiltration potential, street trees, building footprints, and the plot with planned rain gardens on top" width="88%">
  <br><sub>What the import stacks into one site: terrain, infiltration potential, trees, buildings, and the plot the agent designs on.</sub>
</p>

`ImportSiteHandler` runs every `SiteImportStep` in priority order, passing one `SiteImportContext`
along. Steps are discovered by `#[AutowireIterator]`, so adding one is a single class.

```mermaid
flowchart LR
    start(["ImportSite<br/>message"]) --> osm
    osm["<b>60 · FetchOpenStreetMap</b><br/><small>Overpass: the ways the point lies in,<br/>buildings, streets, trees</small>"]
    official["<b>50 · ApplyOfficialLayers</b><br/><small>every MapLayer that covers the area:<br/>Hamburg's buildings and street trees</small>"]
    build["<b>40 · BuildSite</b><br/><small>project to plan mm, choose the plot,<br/>classify buildings (BuildingTags)</small>"]
    terrain["<b>30 · ImportTerrain</b><br/><small>DGM1 by HTTP range requests,<br/>2 m and 4 m height grids</small>"]
    cond["<b>20 · CollectSiteConditions</b><br/><small>infiltration potential,<br/>data sources and licences</small>"]
    ground["<b>10 · GroundSite</b><br/><small>stand every upright object<br/>on the terrain</small>"]
    done(["complete → ready<br/><small>then ImportSurroundings</small>"])

    osm --> official --> build --> terrain --> cond --> ground --> done

    classDef step fill:#e0f2fe,stroke:#0369a1,color:#082f49
    classDef hh fill:#ccfbf1,stroke:#0f766e,color:#042f2e
    classDef edge fill:#0f766e,stroke:#0f766e,color:#ffffff
    class osm,build,ground step
    class official,terrain,cond hh
    class start,done edge
```

<sub>Teal steps use Hamburg's official data and do nothing outside Hamburg; blue steps run everywhere.</sub>

- **One vocabulary for all sources.** Hamburg's datasets are translated into Overpass-like
  elements with OpenStreetMap tags (`building=school`, `height`, `roof:shape`, `diameter_crown`),
  so `SiteImporter` and `BuildingTags` never know where a building came from.
- **The plot** is the most specific polygon the point lies in (leisure, then amenity, then landuse,
  the smaller one winning), or a 40 m square.
- **Surroundings** (the real city out to 350 m) are imported by an `#[AsCompletedListener]` once
  the site is in place. If they fail, the plan does not.

## 5. Lifecycles

Both long-running jobs are Symfony Workflow state machines declared in their own class
(`#[AsWorkflow]`, one `#[Transition]` per constant). An `#[AsEnteredListener]` saves the subject
in every state it enters, which is what the page and the agent poll.

<table>
<tr>
<th>Plan import · <code>PlanImport</code></th>
<th>Building simulation · <code>SimulationRun</code></th>
</tr>
<tr>
<td>

```mermaid
stateDiagram-v2
    direction TB
    [*] --> new
    new --> pending: request
    pending --> importing: start
    importing --> ready: complete
    pending --> failed: fail
    importing --> failed: fail
    ready --> pending: request
    failed --> pending: request
    ready --> [*]
```

</td>
<td>

```mermaid
stateDiagram-v2
    direction TB
    [*] --> queued: simulate_buildings
    queued --> running: start
    running --> done: finish
    queued --> failed: plan is gone
    running --> failed: no buildings or SimStadt failed
    done --> [*]
    failed --> [*]
```

</td>
</tr>
</table>

**Why a lock as well as a state machine?** The cache pool has no compare-and-set. `request` and
`start` read the subject again and apply the transition under a per-subject lock
(`symfony/lock`), so two clicks queue a plan once and two workers never import it twice. The lock
is released before dispatching, because a synchronous dispatch (the console's re-import) runs the
handler in the same process.

## 6. One chat turn

<p align="center">
  <img src="images/human-and-agent.jpg" alt="A human hand and a luminous agent hand made of connected nodes working on the same schoolyard model; the agent snaps a rain garden into place along guide lines" width="88%">
  <br><sub>The person and the agent edit the same plan. The agent works only through tools, and the server snaps what it places to the rules.</sub>
</p>

A turn is several model calls with tool calls in between, 40 to 60 seconds when the agent places
interventions. The user watches the design appear because the page re-renders while the agent
works.

```mermaid
sequenceDiagram
    autonumber
    actor U as Planner
    participant JS as plan_chat_controller.js
    participant C as ChatController
    participant A as PlanAssistant
    participant M as Claude
    participant T as /mcp tools
    participant S as Stores
    participant LC as PlanWorkspace

    U->>JS: "Where would rain gardens help most?"
    JS->>C: POST /plan/{id}/chat
    C->>A: ask(planId, message)
    A->>A: history + "plan_id, interface_language"
    A->>M: messages
    loop until the model answers in text
        M->>T: tool call, e.g. get_site_conditions
        T->>S: read or edit the plan
        T-->>M: JSON result
    end
    par every 2.5 s while the agent works
        JS--)LC: plan:changed
        LC->>S: re-render from the stored plan
        LC--)U: the design grows in the 3D view
    end
    M-->>A: reply
    A->>S: record the turn (ConversationStore)
    A-->>C: reply text
    C-->>JS: 200 { reply }
    JS--)LC: plan:changed (final)
```

**The agent's toolbox** (all on `/mcp`, exposed to the model with the prefix `local_`):

| Look | Design | Measure | Place by rules | Buildings |
|---|---|---|---|---|
| `get_site`<br/>`get_site_conditions`<br/>`list_object_types`<br/>`search_place` · `load_site` | `add_objects`<br/>`update_object`<br/>`remove_object`<br/>`clear_interventions` | `calculate_impact`<br/>`set_design_storm` | `propose_placements`<br/>`place_candidate`<br/>`get_zones` · `describe_zone` | `simulate_buildings`<br/>`get_building_simulation` |

The tools are stateless: the plan id travels with every message. The system prompt
(`config/prompts/spongecity.md`) tells the agent to look first, design with tools, measure before
it claims anything, and never state a number that did not come from a tool.

## 7. Placing an intervention

<p align="center">
  <img src="images/rain-garden-section.jpg" alt="Cross-section of a rain garden: water from a downspout and the asphalt flows into a planted basin over mulch, soil and a gravel storage layer, slowly infiltrating, with an overflow pipe and a tree's root zone beside it" width="88%">
  <br><sub>What <code>RainGardenSiting</code> sizes: a basin that takes the runoff of the sealed ground upstream, stores it and lets it soak away, kept clear of walls and root zones.</sub>
</p>

A language model is good at deciding that "a rain garden at the low end of the yard" makes sense
and bad at keeping its corners off a wall. So the work is split.

```mermaid
flowchart LR
    agent(["Agent"]) -- "propose_placements<br/>type, area, near" --> P

    subgraph server["Server · App\SpongeCity\Placement"]
        direction TB
        P["Placements<br/><small>service locator by type</small>"]
        G["OpenGround<br/><small>1 m grid: building, path,<br/>root zone, wall distance</small>"]
        R["RunoffModel<br/><small>D8 flow on the 2 m terrain,<br/>sealed area upstream</small>"]
        Z["SurfaceZones<br/><small>yards, parking, access,<br/>sport, play, green</small>"]
        RG["RainGardenSiting<br/><small>grow a basin the way water fills it</small>"]
        PS["PermeableSiting<br/><small>the zone's use picks the material</small>"]
        O["CellOutline<br/><small>trace, straighten, round</small>"]
        PR[("ProposalStore")]
        P --> RG & PS
        RG --> G & R & O
        PS --> G & Z & O
        RG & PS --> PR
    end

    PR -- "candidates with metrics<br/>and reasons" --> agent
    agent -- "place_candidate<br/>id + why" --> F{"still fits?"}
    F -- yes --> E["SiteEditor.add"] --> RS[("RationaleStore<br/><small>why · reasons · alternatives</small>")]
    F -- no --> X["explained refusal"]

    classDef agent fill:#0f766e,stroke:#0f766e,color:#ffffff
    classDef svc fill:#e0f2fe,stroke:#0369a1,color:#082f49
    classDef data fill:#fef3c7,stroke:#b45309,color:#451a03
    classDef decision fill:#ffffff,stroke:#0f766e,color:#042f2e
    class agent agent
    class P,G,R,Z,RG,PS,O,E,X svc
    class PR,RS data
    class F decision
    style server fill:#f0f9ff,stroke:#0369a1,color:#082f49
```

Every placed object keeps a **rationale**: the agent's words, the candidate's reasons and numbers,
and the alternatives it was chosen over. The 3D view floats a badge over it, and a person can
preview any alternative and swap it in. The same rules serve the 3D view's "add here" menu
(`AddHere`), so a person and the agent place things the same way.

## 8. Where state lives

There are no database entities. Everything about a plan lives in the application cache pool,
each kind of object under its own key prefix and the plan's id. The SQLite database holds only
the Messenger queues.

```mermaid
flowchart LR
    id(("plan id"))

    subgraph core["Written by people and the agent"]
        direction TB
        plan["<b>Plan</b><br/><small>title · location · status<br/>storm · site JSON</small>"]
        conv["<b>Conversation</b><br/><small>last 20 turns</small>"]
        rat["<b>Rationales</b><br/><small>why each object is there</small>"]
        prop["<b>Proposal</b> per type<br/><small>latest candidates</small>"]
    end

    subgraph imported["Written by the import"]
        direction TB
        terr["<b>Terrain</b><br/><small>height grids</small>"]
        cond["<b>SiteConditions</b><br/><small>infiltration · sources</small>"]
        sur["<b>Surroundings</b><br/><small>city out to 350 m</small>"]
    end

    sim["<b>Simulation</b> per workflow<br/><small>SimStadt results</small>"]

    id --> plan & conv & rat & prop
    id --> terr & cond & sur
    id --> sim

    classDef key fill:#0f766e,stroke:#0f766e,color:#ffffff
    classDef data fill:#fef3c7,stroke:#b45309,color:#451a03
    class id key
    class plan,conv,rat,prop,terr,cond,sur,sim data
    style core fill:#fffbeb,stroke:#b45309,color:#451a03
    style imported fill:#fffbeb,stroke:#b45309,stroke-dasharray:4 3,color:#451a03
```

| Store | Key | Read by |
|---|---|---|
| `PlanStore` | `spongecity_plan_{id}` | everything |
| `ConversationStore` | `spongecity_conversation_{id}` | chat, plan page |
| `RationaleStore` | `spongecity_rationales_{id}` | workspace, placement tools |
| `ProposalStore` | `spongecity_proposal_{id}_{type}` | `place_candidate` |
| `TerrainStore` | `spongecity_terrain_{id}` | 3D view, site editor, agent |
| `SiteConditionsStore` | `spongecity_conditions_{id}` | 3D view overlay, agent |
| `SurroundingsStore` | `spongecity_surroundings_{id}` | 3D view |
| `SimulationStore` | `spongecity_simstadt_{id}_{workflow}` | agent |

All of them extend `CacheStore`, which is fine for a demo and not for production: swapping
`PlanStore` and its siblings for a database implementation is the main step towards running this
for real.

## 9. From values to pixels

A plan is a handful of configuration values (`storm`, `site`). The engine turns them into a
priced product, a measured impact and a scene the browser draws.

```mermaid
flowchart LR
    values[/"Plan values<br/><small>storm · site JSON</small>"/]
    resolver["ConfigurationResolver"]
    def["SpongeCityDefinition<br/><small>plot + interventions as Parts</small>"]
    bom["BillOfMaterials → Quote<br/><small>quantities and cost per SKU</small>"]
    calc["ImpactCalculator<br/><small>baseline vs design</small>"]
    eval["Evaluation.scene()<br/><small>product + SiteBuilder city</small>"]
    ser["SceneSerializer"]
    view["three.js SceneRenderer<br/><small>quality tiers · governor</small>"]
    report[/"ImpactReport<br/><small>metrics · bases · warnings</small>"/]

    values --> resolver --> def
    def --> bom
    def --> eval --> ser -- "scene JSON (metres)" --> view
    values --> calc --> report

    classDef io fill:#fef3c7,stroke:#b45309,color:#451a03
    classDef eng fill:#ede9fe,stroke:#6d28d9,color:#2e1065
    classDef ui fill:#ccfbf1,stroke:#0f766e,color:#042f2e
    class values,report io
    class resolver,def,bom,calc,eval,ser eng
    class view ui
```

- **Units:** millimetres on the server, metres in the browser. Plan coordinates run x east and
  z south, from the searched point.
- **Interventions are the product:** they carry SKUs, so they are priced, listed in the bill of
  quantities, framed by the camera and named on hover. The real city around them is scenery.
- **The impact is a planning-level estimate, not a simulation:** the plot is rasterised, each cell
  takes its top-most surface, and runoff coefficients follow DWA-A 138 and DIN 1986-100. Every
  metric states its basis.

---

<sub>Illustrations are concept art (generated with Higgsfield) and show the idea, not the app's screens. Diagrams are [Mermaid](https://mermaid.js.org/), rendered by GitHub and most editors. Edit them as text; keep the colour classes (teal: entry points and people, blue: services, violet: engine, amber: data) so the pages read as one set.</sub>
