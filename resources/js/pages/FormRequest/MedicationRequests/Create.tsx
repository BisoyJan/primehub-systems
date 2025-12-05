import { Head, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { useFlashMessage, usePageLoading, usePageMeta } from '@/hooks';
import { PageHeader } from '@/components/PageHeader';
import { LoadingOverlay } from '@/components/LoadingOverlay';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle, ArrowLeft, ArrowRight, Check, ChevronsUpDown } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';

interface User {
    id: number;
    name: string;
    email: string;
}

interface CreateProps {
    medicationTypes: string[];
    onsetOptions: string[];
    canRequestForOthers: boolean;
    users?: User[] | null;
}

export default function Create({ medicationTypes, onsetOptions, canRequestForOthers, users }: CreateProps) {
    const { title, breadcrumbs } = usePageMeta({
        title: 'New Medication Request',
        breadcrumbs: [
            { title: 'Medication Requests', href: '/form-requests/medication-requests' },
        ],
    });

    useFlashMessage();
    const isPageLoading = usePageLoading();
    const [currentStep, setCurrentStep] = useState(1);
    const [isUserPopoverOpen, setIsUserPopoverOpen] = useState(false);
    const [userSearchQuery, setUserSearchQuery] = useState('');

    // Filter users based on search query
    const filteredUsers = useMemo(() => {
        if (!users) return [];
        if (!userSearchQuery) return users;
        const query = userSearchQuery.toLowerCase();
        return users.filter(user =>
            user.name.toLowerCase().includes(query) ||
            user.email.toLowerCase().includes(query)
        );
    }, [users, userSearchQuery]);

    const { data, setData, post, processing, errors } = useForm({
        requested_for_user_id: undefined as number | undefined,
        medication_type: '',
        reason: '',
        onset_of_symptoms: '',
        agrees_to_policy: false,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/form-requests/medication-requests');
    };

    const goToNextStep = () => {
        // Validate step 1 fields
        const missingFields: string[] = [];

        if (canRequestForOthers && !data.requested_for_user_id) {
            missingFields.push('Employee');
        }
        if (!data.medication_type) {
            missingFields.push('Type of Medication');
        }
        if (!data.reason) {
            missingFields.push('Reason for Request');
        }
        if (!data.onset_of_symptoms) {
            missingFields.push('Onset of Symptoms');
        }

        if (missingFields.length > 0) {
            toast.error('Please fill in required fields', {
                description: missingFields.join(', '),
            });
            return;
        }

        setCurrentStep(2);
    };

    const goToPreviousStep = () => {
        setCurrentStep(1);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-3 relative">
                <LoadingOverlay isLoading={isPageLoading} />

                <PageHeader
                    title="Medication Request Form"
                    description="Submit a request for medication from the office"
                />

                <div className="max-w-3xl mx-auto w-full">
                    {/* Progress Indicator */}
                    <div className="mb-8">
                        <div className="flex items-center justify-center gap-4">
                            <div className="flex items-center gap-2">
                                <div
                                    className={`h-10 w-10 rounded-full flex items-center justify-center border-2 ${currentStep >= 1
                                        ? 'bg-primary border-primary text-primary-foreground'
                                        : 'border-muted-foreground text-muted-foreground'
                                        }`}
                                >
                                    {currentStep > 1 ? <Check className="h-5 w-5" /> : '1'}
                                </div>
                                <span className={currentStep >= 1 ? 'font-medium' : 'text-muted-foreground'}>
                                    Request Details
                                </span>
                            </div>
                            <div className="w-24 h-0.5 bg-muted" />
                            <div className="flex items-center gap-2">
                                <div
                                    className={`h-10 w-10 rounded-full flex items-center justify-center border-2 ${currentStep >= 2
                                        ? 'bg-primary border-primary text-primary-foreground'
                                        : 'border-muted-foreground text-muted-foreground'
                                        }`}
                                >
                                    2
                                </div>
                                <span className={currentStep >= 2 ? 'font-medium' : 'text-muted-foreground'}>
                                    Policy Agreement
                                </span>
                            </div>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit}>
                        {/* Step 1: Request Details */}
                        {currentStep === 1 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Request Details</CardTitle>
                                    <CardDescription>
                                        Please provide your information and medication needs
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    {/* User Selection for Privileged Roles */}
                                    {canRequestForOthers && users && (
                                        <div className="space-y-2">
                                            <Label htmlFor="requested_for_user_id">
                                                Request for Employee <span className="text-red-500">*</span>
                                            </Label>
                                            <Popover open={isUserPopoverOpen} onOpenChange={setIsUserPopoverOpen}>
                                                <PopoverTrigger asChild>
                                                    <Button
                                                        variant="outline"
                                                        role="combobox"
                                                        aria-expanded={isUserPopoverOpen}
                                                        className="w-full justify-between font-normal"
                                                    >
                                                        <span className="truncate">
                                                            {data.requested_for_user_id
                                                                ? users.find(u => u.id === data.requested_for_user_id)?.name || "Select employee..."
                                                                : "Select employee..."}
                                                        </span>
                                                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                    </Button>
                                                </PopoverTrigger>
                                                <PopoverContent className="w-full p-0" align="start">
                                                    <Command shouldFilter={false}>
                                                        <CommandInput
                                                            placeholder="Search employee..."
                                                            value={userSearchQuery}
                                                            onValueChange={setUserSearchQuery}
                                                        />
                                                        <CommandList>
                                                            <CommandEmpty>No employee found.</CommandEmpty>
                                                            <CommandGroup>
                                                                {filteredUsers.map((user) => (
                                                                    <CommandItem
                                                                        key={user.id}
                                                                        value={user.name}
                                                                        onSelect={() => {
                                                                            setData('requested_for_user_id', user.id);
                                                                            setIsUserPopoverOpen(false);
                                                                            setUserSearchQuery('');
                                                                        }}
                                                                        className="cursor-pointer"
                                                                    >
                                                                        <Check
                                                                            className={`mr-2 h-4 w-4 shrink-0 ${data.requested_for_user_id === user.id
                                                                                ? "opacity-100"
                                                                                : "opacity-0"
                                                                                }`}
                                                                        />
                                                                        <div className="flex flex-col">
                                                                            <span>{user.name}</span>
                                                                            <span className="text-xs text-muted-foreground">{user.email}</span>
                                                                        </div>
                                                                    </CommandItem>
                                                                ))}
                                                            </CommandGroup>
                                                        </CommandList>
                                                    </Command>
                                                </PopoverContent>
                                            </Popover>
                                            {errors.requested_for_user_id && (
                                                <p className="text-sm text-red-500">{errors.requested_for_user_id}</p>
                                            )}
                                        </div>
                                    )}

                                    {/* Type of Medication */}
                                    <div className="space-y-3">
                                        <Label>
                                            Type of Medication <span className="text-red-500">*</span>
                                        </Label>
                                        <RadioGroup
                                            value={data.medication_type}
                                            onValueChange={(value) => setData('medication_type', value)}
                                        >
                                            {medicationTypes.map((type) => (
                                                <div key={type} className="flex items-center space-x-2">
                                                    <RadioGroupItem value={type} id={type} />
                                                    <Label htmlFor={type} className="cursor-pointer font-normal">
                                                        {type}
                                                    </Label>
                                                </div>
                                            ))}
                                        </RadioGroup>
                                        {errors.medication_type && (
                                            <p className="text-sm text-red-500">{errors.medication_type}</p>
                                        )}
                                    </div>

                                    {/* Reason for Request */}
                                    <div className="space-y-2">
                                        <Label htmlFor="reason">
                                            Reason for Request <span className="text-red-500">*</span>
                                        </Label>
                                        <Textarea
                                            id="reason"
                                            value={data.reason}
                                            onChange={(e) => setData('reason', e.target.value)}
                                            placeholder="Please describe your symptoms"
                                            rows={4}
                                            required
                                        />
                                        {errors.reason && (
                                            <p className="text-sm text-red-500">{errors.reason}</p>
                                        )}
                                    </div>

                                    {/* Onset of symptoms */}
                                    <div className="space-y-3">
                                        <Label>
                                            Onset of symptoms <span className="text-red-500">*</span>
                                        </Label>
                                        <RadioGroup
                                            value={data.onset_of_symptoms}
                                            onValueChange={(value) => setData('onset_of_symptoms', value)}
                                        >
                                            {onsetOptions.map((option) => (
                                                <div key={option} className="flex items-center space-x-2">
                                                    <RadioGroupItem value={option} id={option} />
                                                    <Label htmlFor={option} className="cursor-pointer font-normal capitalize">
                                                        {option.replace(/_/g, ' ')}
                                                    </Label>
                                                </div>
                                            ))}
                                        </RadioGroup>
                                        {errors.onset_of_symptoms && (
                                            <p className="text-sm text-red-500">{errors.onset_of_symptoms}</p>
                                        )}
                                    </div>

                                    <div className="flex justify-end">
                                        <Button type="button" onClick={goToNextStep}>
                                            Next
                                            <ArrowRight className="ml-2 h-4 w-4" />
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {/* Step 2: Policy Agreement */}
                        {currentStep === 2 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Policy Agreement</CardTitle>
                                    <CardDescription>Please review and agree to our medication policy</CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    <Alert>
                                        <AlertCircle className="h-4 w-4" />
                                        <AlertDescription>
                                            <strong>Please note:</strong> If symptoms persist beyond today, we encourage you to{' '}
                                            <strong>bring your own medication to the office</strong> to ensure your well-being and
                                            minimize interruptions.
                                        </AlertDescription>
                                    </Alert>

                                    <div className="space-y-3">
                                        <Label>
                                            Do you agree? <span className="text-red-500">*</span>
                                        </Label>
                                        <RadioGroup
                                            value={data.agrees_to_policy ? 'yes' : 'no'}
                                            onValueChange={(value) => setData('agrees_to_policy', value === 'yes')}
                                        >
                                            <div className="flex items-center space-x-2">
                                                <RadioGroupItem value="yes" id="yes" />
                                                <Label htmlFor="yes" className="font-normal cursor-pointer">
                                                    Yes
                                                </Label>
                                            </div>
                                            <div className="flex items-center space-x-2">
                                                <RadioGroupItem value="no" id="no" />
                                                <Label htmlFor="no" className="font-normal cursor-pointer">
                                                    No
                                                </Label>
                                            </div>
                                        </RadioGroup>
                                        {errors.agrees_to_policy && (
                                            <p className="text-sm text-red-500">{errors.agrees_to_policy}</p>
                                        )}
                                    </div>

                                    <div className="flex justify-between">
                                        <Button type="button" variant="outline" onClick={goToPreviousStep}>
                                            <ArrowLeft className="mr-2 h-4 w-4" />
                                            Back
                                        </Button>
                                        <Button type="submit" disabled={processing || !data.agrees_to_policy}>
                                            Submit Request
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
