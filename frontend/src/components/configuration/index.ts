/**
 * The shared configuration-lifecycle components (Phase 7.6, Tier 0).
 *
 * Not yet wired into the 26 master pages — that is the next wave. Adopting
 * them on a page means: render `ActiveStatusTag` for the state,
 * `ConfigurationRowActions` from the server's `can` block, and open
 * `DeleteConfigurationModal` driven by `useConfigurationLifecycle`.
 */
export { ActiveStatusTag } from './ActiveStatusTag';
export { ConfigurationRowActions } from './ConfigurationRowActions';
export { DeleteConfigurationModal } from './DeleteConfigurationModal';
export { useConfigurationLifecycle } from './useConfigurationLifecycle';
export type { ConfigurationId, ReasonedChange, UseConfigurationLifecycleOptions } from './useConfigurationLifecycle';
export * from './configurationWords';
export * from './pickerOptions';
export type {
    BlockingReason,
    ConfigurationAbilities,
    ConfigurationAction,
    ConfigurationActionKey,
    ConfigurationInUse,
} from './types';
