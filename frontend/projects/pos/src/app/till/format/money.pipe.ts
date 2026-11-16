import { Pipe, PipeTransform } from '@angular/core';
import { formatMoney } from '@flexipos/shared';

/**
 * A money string as the till shows it (D-7, AC-78): the server's digits, thousands grouped, at
 * least two decimals, never rounded, followed by the currency code the server supplied.
 */
@Pipe({ name: 'money' })
export class MoneyPipe implements PipeTransform {
  transform(value: string | null | undefined, currency?: string | null): string {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    const text = formatMoney(value);
    return currency ? `${text} ${currency}` : text;
  }
}
