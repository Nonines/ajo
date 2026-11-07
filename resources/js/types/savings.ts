export type SavingsProductContract = {
    name: string;
    disclosure: string;
    minimum: string;
    maximum: string;
    withholding_bp: string;
    fee: string;
    terms: { days: number; annual_rate_bp: string }[];
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
};
