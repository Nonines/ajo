export type SavingsProductContract = {
    name: string;
    disclosure: string;
    minimum: string;
    maximum: string;
    withholding_bp: string;
    fee: string;
    terms: { days: number; annual_rate_bp: string }[];
    interest: 'simple' | 'daily' | '30_days';
    lock: 'until_maturity' | 'terminable' | 'uncancellable';
    minimum_holding_days?: number | null;
    forfeiture_bp?: string;
    principal_penalty_bp?: string;
};

export type SavingsContract = SavingsProductContract & {
    principal: string;
    days: number;
    annual_rate_bp: string;
};

export type SavingsPlan = {
    id: number;
    state: string;
    contract: SavingsContract;
    createdAt: string;
    fundingDeadline: string;
    fundedAt: string | null;
    accrualStartsAt: string | null;
    maturesAt: string | null;
    closureReason: string | null;
    parentPlanId: number | null;
    rolloverElection: {
        enabled: boolean;
        days: number;
        amount_mode: 'original_principal' | 'all_net';
    } | null;
};
