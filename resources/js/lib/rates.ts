// Format nonnegative basis points as percent without losing integer precision.
export function formatBasisPoints(value: string): string {
    const rate = BigInt(value);
    return `${rate / 100n}.${(rate % 100n).toString().padStart(2, '0')}%`;
}
