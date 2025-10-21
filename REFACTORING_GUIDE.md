# Priority 3 Enhancements - Refactoring Guide

## 📚 Overview

This document describes the new reusable hooks and components created to reduce code duplication and improve consistency across the application.

## 🎯 Created Hooks

### 1. `usePageMeta(options)`
**Location:** `resources/js/hooks/use-page-meta.ts`

Manages page metadata (title, breadcrumbs, description) consistently.

**Usage:**
```tsx
const { title, breadcrumbs } = usePageMeta({
    title: "Account Management",
    breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
});

return (
    <AppLayout breadcrumbs={breadcrumbs}>
        <Head title={title} />
        {/* content */}
    </AppLayout>
);
```

**Benefits:**
- Centralized page metadata management
- Type-safe breadcrumbs
- Prevents duplication

---

### 2. `useFlashMessage()`
**Location:** `resources/js/hooks/use-flash-message.ts`

Automatically displays toast notifications for server flash messages.

**Usage:**
```tsx
export default function MyPage() {
    useFlashMessage(); // That's it! Auto-handles flash messages
    
    return <div>...</div>;
}
```

**Replaces:**
```tsx
// OLD WAY (15+ lines)
const { flash } = usePage().props;
useEffect(() => {
    if (!flash?.message) return;
    if (flash.type === "error") {
        toast.error(flash.message);
    } else {
        toast.success(flash.message);
    }
}, [flash?.message, flash?.type]);
```

**Benefits:**
- Reduces boilerplate by ~15 lines per page
- Consistent flash message handling
- Supports success, error, warning, info types

---

### 3. `usePageLoading()`
**Location:** `resources/js/hooks/use-page-loading.ts`

Tracks Inertia page transition loading states.

**Usage:**
```tsx
const isLoading = usePageLoading();

return (
    <div>
        <LoadingOverlay isLoading={isLoading} />
        {/* content */}
    </div>
);
```

**Benefits:**
- Global loading state for page transitions
- Integrates with Inertia router events
- Better UX with loading indicators

---

### 4. `useLocalLoading()`
**Location:** `resources/js/hooks/use-page-loading.ts`

Manages local loading states for specific actions.

**Usage:**
```tsx
const [isDeleting, setDeleting] = useLocalLoading();

const handleDelete = async (id: number) => {
    setDeleting(true);
    await deleteItem(id);
    setDeleting(false);
};

return <Button disabled={isDeleting}>Delete</Button>;
```

---

## 🧩 Created Components

### 1. `<PageHeader>`
**Location:** `resources/js/components/PageHeader.tsx`

Standardized page header with title, description, and action buttons.

**Usage:**
```tsx
<PageHeader
    title="RAM Specifications"
    description="Manage RAM component specifications"
    createLink={create().url}
    createLabel="Add RAM Spec"
>
    <SearchBar {...searchProps} />
</PageHeader>
```

**Props:**
- `title` (required): Page title
- `description` (optional): Page description
- `createLink` (optional): URL for create button
- `createLabel` (optional): Label for create button (default: "Add New")
- `actions` (optional): Custom action buttons
- `children` (optional): Additional content (e.g., search bar)

**Benefits:**
- Consistent page headers across all pages
- Responsive design built-in
- Reduces 20-30 lines per page

---

### 2. `<SearchBar>`
**Location:** `resources/js/components/SearchBar.tsx`

Reusable search input with submit button.

**Usage:**
```tsx
<SearchBar
    value={searchTerm}
    onChange={setSearchTerm}
    onSubmit={handleSearch}
    placeholder="Search specifications..."
/>
```

**Props:**
- `value` (required): Current search value
- `onChange` (required): Callback when value changes
- `onSubmit` (required): Callback when form submits
- `placeholder` (optional): Input placeholder
- `className` (optional): Additional CSS classes

---

### 3. `<DeleteConfirmDialog>`
**Location:** `resources/js/components/DeleteConfirmDialog.tsx`

Consistent delete confirmation dialog with AlertDialog.

**Usage:**
```tsx
<DeleteConfirmDialog
    onConfirm={() => handleDelete(item.id)}
    title="Delete RAM Specification"
    description="Are you sure you want to delete this item?"
/>
```

**Props:**
- `onConfirm` (required): Callback when deletion confirmed
- `title` (optional): Dialog title
- `description` (optional): Dialog description
- `triggerLabel` (optional): Button label (default: "Delete")
- `triggerClassName` (optional): Button CSS classes
- `disabled` (optional): Disable the button

**Benefits:**
- Consistent delete UI/UX
- Built-in accessibility
- Reduces 30+ lines per page

---

### 4. `<LoadingOverlay>` & `<LoadingSpinner>`
**Location:** `resources/js/components/LoadingOverlay.tsx`

Loading indicators for page and component-level loading states.

**Usage:**
```tsx
// Full page overlay
<LoadingOverlay isLoading={isLoading} message="Loading..." />

// Inline spinner
<LoadingSpinner className="mr-2" />
```

**Props (LoadingOverlay):**
- `isLoading` (optional): Show/hide overlay
- `message` (optional): Loading message
- `fullScreen` (optional): Cover entire screen vs current container

---

## 📊 Impact Analysis

### Code Reduction Per Page

**Before Refactoring (Typical Index Page):**
- ~300 lines of code
- Duplicate flash message handling (15 lines)
- Duplicate search UI (20 lines)
- Duplicate page header (25 lines)
- Duplicate delete dialogs (35 lines per action)

**After Refactoring:**
- ~180 lines of code (**40% reduction**)
- Single `useFlashMessage()` hook call
- Single `<SearchBar>` component
- Single `<PageHeader>` component
- Single `<DeleteConfirmDialog>` per action

### Files That Can Be Refactored

✅ **High Priority (Most Duplication):**
1. `resources/js/pages/Computer/RamSpecs/Index.tsx`
2. `resources/js/pages/Computer/DiskSpecs/Index.tsx`
3. `resources/js/pages/Computer/ProcessorSpecs/Index.tsx`
4. `resources/js/pages/Computer/PcSpecs/Index.tsx`
5. `resources/js/pages/Computer/Stocks/Index.tsx`

🔄 **Medium Priority:**
6. `resources/js/pages/Account/Index.tsx`
7. `resources/js/pages/Station/Index.tsx`
8. `resources/js/pages/PC Transfer/Index.tsx`

📝 **Create/Edit Pages:**
- Can use `usePageMeta()` and `useFlashMessage()`
- Form components could be further standardized

---

## 🚀 Migration Guide

### Step 1: Update Imports
```tsx
// Add these imports
import { usePageMeta, useFlashMessage, usePageLoading } from "@/hooks";
import { PageHeader } from "@/components/PageHeader";
import { SearchBar } from "@/components/SearchBar";
import { DeleteConfirmDialog } from "@/components/DeleteConfirmDialog";
import { LoadingOverlay } from "@/components/LoadingOverlay";
```

### Step 2: Replace Flash Message Handling
```tsx
// OLD: Remove this
const { flash } = usePage().props;
useEffect(() => {
    if (!flash?.message) return;
    if (flash.type === "error") {
        toast.error(flash.message);
    } else {
        toast.success(flash.message);
    }
}, [flash?.message, flash?.type]);

// NEW: Add this
useFlashMessage();
```

### Step 3: Replace Page Metadata
```tsx
// OLD: Remove this
const breadcrumbs = [{ title: "Accounts", href: "/accounts" }];

// NEW: Add this
const { title, breadcrumbs } = usePageMeta({
    title: "Account Management",
    breadcrumbs: [{ title: "Accounts", href: accountsIndex().url }]
});
```

### Step 4: Replace Page Header
```tsx
// OLD: Remove this (20-30 lines)
<div className="flex flex-col gap-3">
    <h2 className="text-lg md:text-xl font-semibold">RAM Specs Management</h2>
    <form onSubmit={handleSearch} className="flex gap-2">
        <input type="text" ... />
        <Button type="submit">Search</Button>
    </form>
    <Link href={create.url()}>
        <Button>Add Model</Button>
    </Link>
</div>

// NEW: Add this (5 lines)
<PageHeader
    title="RAM Specs Management"
    createLink={create.url()}
    createLabel="Add RAM Spec"
>
    <SearchBar value={form.data.search} onChange={...} onSubmit={...} />
</PageHeader>
```

### Step 5: Replace Delete Dialogs
```tsx
// OLD: Remove this (30+ lines)
<AlertDialog>
    <AlertDialogTrigger>
        <Button variant="destructive">Delete</Button>
    </AlertDialogTrigger>
    <AlertDialogContent>
        <AlertDialogHeader>...</AlertDialogHeader>
        <AlertDialogFooter>...</AlertDialogFooter>
    </AlertDialogContent>
</AlertDialog>

// NEW: Add this (4 lines)
<DeleteConfirmDialog
    onConfirm={() => handleDelete(item.id)}
    title="Delete RAM Specification"
    description="Are you sure?"
/>
```

### Step 6: Add Loading States
```tsx
// Add loading state tracking
const isLoading = usePageLoading();

// Add loading overlay
<div className="relative">
    <LoadingOverlay isLoading={isLoading} />
    {/* your content */}
</div>
```

---

## ✅ Example Refactored Page

See: `resources/js/pages/Computer/RamSpecs/IndexRefactored.example.tsx`

This file demonstrates all new patterns in action and can serve as a template for refactoring other pages.

---

## 📈 Next Steps

1. ✅ Refactor high-priority spec index pages (RamSpecs, DiskSpecs, ProcessorSpecs)
2. ⏳ Refactor medium-priority pages (Accounts, Stations)
3. ⏳ Create generic form components for Create/Edit pages
4. ⏳ Add skeleton loaders for better loading UX
5. ⏳ Document patterns in team wiki/Notion

---

## 💡 Best Practices

1. **Always use `useFlashMessage()`** in all pages that receive flash messages
2. **Use `usePageMeta()`** for consistent breadcrumbs and titles
3. **Use `<PageHeader>`** for all list/index pages
4. **Use `<DeleteConfirmDialog>`** for all delete actions
5. **Add `<LoadingOverlay>`** to improve perceived performance

---

## 🔗 Related Files

- Hooks: `resources/js/hooks/index.ts`
- Components: `resources/js/components/`
- Example: `resources/js/pages/Computer/RamSpecs/IndexRefactored.example.tsx`
