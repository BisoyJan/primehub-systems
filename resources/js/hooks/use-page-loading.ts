import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

/**
 * Custom hook to track page loading state using Inertia's progress events
 * Provides a boolean state that indicates whether a page transition is in progress
 *
 * @example
 * ```tsx
 * export default function MyPage() {
 *   const isLoading = usePageLoading();
 *
 *   return (
 *     <div>
 *       {isLoading && <LoadingSpinner />}
 *       <Content />
 *     </div>
 *   );
 * }
 * ```
 */
export function usePageLoading() {
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        const handleStart = () => setIsLoading(true);
        const handleFinish = () => setIsLoading(false);

        // Listen to Inertia router events
        const startUnsubscribe = router.on('start', handleStart);
        const finishUnsubscribe = router.on('finish', handleFinish);

        return () => {
            startUnsubscribe();
            finishUnsubscribe();
        };
    }, []);

    return isLoading;
}

/**
 * Custom hook for managing local loading states (e.g., for specific actions)
 * Returns a tuple of [isLoading, setLoading] similar to useState
 *
 * @example
 * ```tsx
 * export default function MyComponent() {
 *   const [isDeleting, setDeleting] = useLocalLoading();
 *
 *   const handleDelete = async (id: number) => {
 *     setDeleting(true);
 *     await deleteItem(id);
 *     setDeleting(false);
 *   };
 *
 *   return <Button disabled={isDeleting}>Delete</Button>;
 * }
 * ```
 */
export function useLocalLoading(initialState = false): [boolean, (loading: boolean) => void] {
    const [isLoading, setIsLoading] = useState(initialState);

    const setLoading = (loading: boolean) => {
        setIsLoading(loading);
    };

    return [isLoading, setLoading];
}
