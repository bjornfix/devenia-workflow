# Naming Model

## Canonical product namespace

- PHP Modules and Adapters: `Devenia_Workflow_*`
- WordPress hooks, options, handles, actions, and internal protocol keys: `devenia_workflow_*`
- WordPress abilities: `devenia-workflow/*`
- Plugin directory, main file, text domain, and repository: `devenia-workflow`

## Functional names

Names state the owning function after the product namespace.

- Generic workflow: `Source_Editor_Adapter`, `Mode`,
  `Atomic_Option_Store`, `Execution_Identity`, and `Source_Inventory`.
- Translation: `Translation_Job`, `Translation_Index_Read_Model`,
  `Translation_Read_Models`, and `Translation_Provenance`.

`AI` is not a domain name. It describes one possible worker implementation and
must not prefix Modules, storage, hooks, abilities, or public repository
identity.

## Translation metadata

Post, term, and menu metadata beginning `_devenia_translation_*` remains
translation-specific because those values describe translation relationships,
language, review, localized routes, and provenance. Translation tables retain
`devenia_translation_*` for the same reason.

## State replacement

Release 0.1.574 replaces the plugin on its two owned installations. No alias,
dual-read, or migration layer is retained for the removed persona, Heartbeat,
Assignment, Reservation, or Work Item system. Source Inventory is rebuilt and
the paused Translation Job restarts through the canonical current Interface.
