import { Pipe, PipeTransform } from '@angular/core';
import { formatQuantity } from '@flexipos/shared';

/**
 * A quantity string as the till shows it (D-7, AC-79): the server's digits with trailing zeros
 * trimmed, followed by the unit code snapshot. Never rounded.
 */
@Pipe({ name: 'quantity' })
export class QuantityPipe implements PipeTransform {
  transform(value: string | null | undefined, unitCode?: string | null): string {
    if (value === null || value === undefined || value === '') {
      return '';
    }
    const text = formatQuantity(value);
    return unitCode ? `${text} ${unitCode}` : text;
  }
}
