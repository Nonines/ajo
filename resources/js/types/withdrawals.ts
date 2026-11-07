export type Withdrawal = {
    id: number;
    simulated: boolean;
    reference: string;
    state: string;
    principal: string;
    reservedFee: string;
    actualFee: string | null;
    accountName: string;
    bankName: string;
    accountNumber: string;
    requiresChecker: boolean;
    expiresAt: string;
    createdAt: string;
    canCancel: boolean;
};
