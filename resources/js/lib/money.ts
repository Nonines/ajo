export function formatMoney(kobo: string): string {
    const amount = BigInt(kobo);
    const absolute = amount < 0n ? -amount : amount;
    return `${amount < 0n ? '-' : ''}₦${(absolute / 100n).toLocaleString('en-NG')}.${(absolute % 100n).toString().padStart(2, '0')}`;
}
