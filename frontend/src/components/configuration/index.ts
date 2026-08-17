/**
 * The shared configuration-lifecycle components (Phase 7.6, Tier 0) and the
 * registry that wires them into the master pages (the D-WIRING wave).
 *
 * Adopting them on a page means: render `ConfigurationStatusTag` for the
 * state (it reads the master's own column through the registry), and render
 * `ConfigurationActionsCell` for the row's acts — which reads the server's
 * `can` block, opens `DeleteConfigurationModal` on a delete, and drives all
 * three calls through `useConfigurationLifecycle`. A page supplies its own
 * Edit handler and its own extra buttons; it decides nothing about
 * eligibility.
 */
export { ActiveStatusTag } from './ActiveStatusTag';
export { ConfigurationActionsCell } from './ConfigurationActionsCell';
export { ConfigurationRowActions } from './ConfigurationRowActions';
export { ConfigurationStatusTag } from './ConfigurationStatusTag';
export { DeleteConfigurationModal } from './DeleteConfigurationModal';
export { abilitiesOf, useConfigurationLifecycle } from './useConfigurationLifecycle';
export type { ConfigurationId, ReasonedChange, UseConfigurationLifecycleOptions } from './useConfigurationLifecycle';
export * from './configurationWords';
export * from './entities';
export * from './pickerOptions';
export type {
    BlockingReason,
    ConfigurationAbilities,
    ConfigurationAction,
    ConfigurationActionKey,
    ConfigurationInUse,
} from './types';
