// Custom hooks for consistent application behavior
export { useDebounce } from './use-debounce';
export { useFlashMessage } from './use-flash-message';
export { useLocalLoading, usePageLoading } from './use-page-loading';
export { usePageMeta } from './use-page-meta';
export { useAuthorization, usePermission, useRole } from './use-permission';
export {
    readReturnTo,
    sanitizeReturnTo,
    useReturnTo,
    withReturnTo,
} from './use-return-to';
export { useTwoFactorAuth } from './use-two-factor-auth';
