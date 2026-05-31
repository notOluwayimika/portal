'use client';

import axios from 'axios';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface Guardian {
    id: string;
    full_name: string;
    first_name?: string;
    middle_name?: string | null;
    last_name?: string;
}

interface PasswordModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    guardian: Guardian;
    onUpdate: (updated: Guardian) => void;
}

export function PasswordModal({
    open,
    onOpenChange,
    guardian,
    onUpdate,
}: PasswordModalProps) {
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const passwordMismatch =
        confirmPassword.length > 0 && password !== confirmPassword;
    const isValid = password.length >= 8 && password === confirmPassword;

    const handleClose = (open: boolean) => {
        if (!open) {
            setPassword('');
            setConfirmPassword('');
            setShowPassword(false);
            setShowConfirm(false);
        }

        onOpenChange(open);
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!isValid) {
            return;
        }

        setIsSubmitting(true);

        try {
            const response = await axios.post(
                `/api/guardians/${guardian.id}/password`,
                {
                    password,
                    password_confirmation: confirmPassword,
                },
            );

            if (response.status === 200) {
                handleClose(false);
                onUpdate(guardian);
            }
        } catch (err) {
            console.error(err);
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleClose}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Set Password</DialogTitle>
                    <p className="text-sm text-muted-foreground">
                        Setting password for{' '}
                        <span className="font-medium text-foreground">
                            {guardian.full_name}
                        </span>
                    </p>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="password">Password</Label>
                        <div className="relative">
                            <Input
                                id="password"
                                type={showPassword ? 'text' : 'password'}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                placeholder="Min. 8 characters"
                                className="pr-10"
                                autoComplete="new-password"
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((v) => !v)}
                                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                tabIndex={-1}
                            >
                                {showPassword ? (
                                    <EyeOff size={16} />
                                ) : (
                                    <Eye size={16} />
                                )}
                            </button>
                        </div>
                        {password.length > 0 && password.length < 8 && (
                            <p className="text-xs text-destructive">
                                Password must be at least 8 characters.
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="confirmPassword">
                            Confirm Password
                        </Label>
                        <div className="relative">
                            <Input
                                id="confirmPassword"
                                type={showConfirm ? 'text' : 'password'}
                                value={confirmPassword}
                                onChange={(e) =>
                                    setConfirmPassword(e.target.value)
                                }
                                placeholder="Re-enter password"
                                className={`pr-10 ${passwordMismatch ? 'border-destructive focus-visible:ring-destructive' : ''}`}
                                autoComplete="new-password"
                            />
                            <button
                                type="button"
                                onClick={() => setShowConfirm((v) => !v)}
                                className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                tabIndex={-1}
                            >
                                {showConfirm ? (
                                    <EyeOff size={16} />
                                ) : (
                                    <Eye size={16} />
                                )}
                            </button>
                        </div>
                        {passwordMismatch && (
                            <p className="text-xs text-destructive">
                                Passwords do not match.
                            </p>
                        )}
                    </div>

                    <DialogFooter className="pt-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => handleClose(false)}
                            disabled={isSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={!isValid || isSubmitting}
                        >
                            {isSubmitting ? 'Saving...' : 'Set Password'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
