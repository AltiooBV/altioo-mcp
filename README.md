# Altioo MCP — Model Context Protocol server for iTop

Exposes an iTop instance to MCP clients (Claude, and any other Model Context Protocol
consumer) as a set of tools, resources and prompts, so an assistant can search, read and
update CMDB and ticketing objects through iTop's own ORM and permission model.

Every call goes through `UserRights`, so a client can never see or change more than the
authenticated user could through the console.

## Requirements

| | |
|---|---|
| iTop | **3.2 (current LTS)** or **3.3** |
| PHP | **8.2 – 8.4** |
| iTop modules | `authent-token` 2.2.1 or later (ships with iTop) |

The PHP range is the intersection of what those iTop branches support — 3.3 requires 8.2 as a
floor, and 8.4 is the newest PHP any of them validates. Note that iTop enforces its own ceiling
too: **3.2.0–3.2.2 do not support PHP 8.4** (known issues), so on those you need 8.2 or 8.3.
PHP 8.4 becomes available from iTop 3.2.3-1 onwards.

## What it exposes

**Tools**

| Tool | Purpose |
|---|---|
| `ObjectSearchByOQL` | Search objects with an OQL query |
| `ObjectSearchByClass` | Search objects of a class by attribute criteria |
| `ObjectGet` | Retrieve a single object by class and ID |
| `ObjectGetRelated` | Walk a named relation (impacts, depends on…) for impact analysis |
| `ObjectCreate` | Create an object |
| `ObjectUpdate` | Update an object's attributes |
| `ObjectApplyStimulus` | Apply a lifecycle stimulus (state transition) |
| `ObjectDelete` | Delete an object, reporting its deletion plan |

**Resources**

| URI | Content |
|---|---|
| `itop://core/version` | iTop version and edition |
| `itop://core/current-user` | The authenticated user and their profiles |
| `itop://core/classes` | The list of classes in the datamodel |
| `itop://core/class/{class}` | One class in detail: attributes, relations, lifecycle |

**Prompts**

| Prompt | Purpose |
|---|---|
| `MyOpenTickets` | Summarise the current user's open tickets |

## Installation

1. Unzip the extension into your iTop `extensions/` directory, so that you get
   `<itop>/extensions/altioo-mcp/`.
2. Run the iTop setup (`<itop-url>/setup/`) and tick **Altioo MCP iTop Extension** in the
   list of extensions.
3. Complete the setup so the datamodel is compiled.

The archive ships its own `vendor/` directory — do **not** run `composer install` on a
production instance.

## Granting access

Access is gated twice, and both gates must pass.

**1. A profile.** By default only users holding **Administrator** or **MCP Services User**
may reach the endpoint. `MCP Services User` is created by this extension; like iTop's own
`REST Services User`, it grants no data rights of its own — it only marks a user as allowed
through. What the client can actually read or write still comes from the user's other
profiles, so grant it alongside a functional profile, never on its own.

Change the allowed list with the `mcp_allowed_profiles` module parameter (below), or set
`secure_mcp_services` to `false` to drop the profile check entirely.

**2. A token.** Create a Personal Token (My Account → Personal Tokens) or a User Token with
the **MCP** scope, and give it to the client.

## Endpoint

```
https://<your-itop>/extensions/altioo-mcp/index.php
```

Point your MCP client at that URL and authenticate with the token above.

## Configuration

All settings live under the `altioo-mcp` module in `conf/<env>/config-itop.php`:

```php
'altioo-mcp' => array(
    'secure_mcp_services' => true,
    'mcp_allowed_profiles' => array('Administrator', 'MCP Services User'),
    'log_mcp_service' => true,
    'log_mcp_method' => array('resources/read', 'tools/call', 'prompts/get', 'exceptions'),
    'log_mcp_level' => 'error',
),
```

| Setting | Default | Effect |
|---|---|---|
| `secure_mcp_services` | `true` | When true, callers must hold one of `mcp_allowed_profiles`. Setting it to `false` opens the endpoint to every authenticated user |
| `mcp_allowed_profiles` | `Administrator`, `MCP Services User` | Profiles allowed through the endpoint |
| `log_mcp_service` | `true` | Write an `EventMCPService` audit entry per call |
| `log_mcp_method` | see above | Which MCP methods are audited |
| `log_mcp_level` | `error` | `error` logs failures only; `info` logs everything; `debug` additionally records the raw request parameters |

### Audit trail

Calls are recorded as **MCP Service Call** (`EventMCPService`) objects, visible in the
console. Each entry holds the MCP method, the tool or resource invoked, the outcome, and the
calling user.

> `log_mcp_level => 'debug'` stores the **raw JSON request parameters**, which may contain
> data your users would not expect to find in an audit log. Use it for troubleshooting, not
> as a standing setting.

## Security

The endpoint is a public HTTP entry point. Beyond the two gates above, follow
[iTop's security guidance](https://www.itophub.io/wiki/page?id=latest:install:security) —
in particular serve iTop over HTTPS with HSTS, and set `session.cookie_secure`,
`session.cookie_httponly` and `zend.exception_ignore_args` in PHP.

Grant `MCP Services User` deliberately. An MCP client is driven by a language model acting
on instructions that may come from outside your organisation, so treat the profiles you pair
it with as the real blast radius — start read-only and widen only as needed.

## Development

```bash
composer install
composer test:unit
```

The unit suite needs neither iTop nor a database. The integration suite additionally needs a
live iTop with the module installed, and skips itself otherwise:

```bash
ITOP_ROOT=/path/to/itop/web composer test:integration
```

Build a release archive with `composer install --no-dev`; `exclude.txt` lists what is kept
out of the package.

## License

[AGPL-3.0-or-later](LICENSE), matching iTop itself.
