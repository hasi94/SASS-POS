import { TestBed } from '@angular/core/testing';
import { DialogHost, SCAN_FIELD_ATTRIBUTE } from './dialog-host';

describe('DialogHost (AC-21, AC-82)', () => {
  let host: DialogHost;
  let opener: HTMLButtonElement;
  let scan: HTMLInputElement;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [DialogHost] });
    host = TestBed.inject(DialogHost);
    document.body.innerHTML = '';
    opener = document.createElement('button');
    scan = document.createElement('input');
    scan.setAttribute(SCAN_FIELD_ATTRIBUTE, '');
    document.body.append(opener, scan);
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('starts with nothing open', () => {
    expect(host.isOpen()).toBe(false);
    expect(host.current()).toBeNull();
  });

  it('opens a dialog with its data and resolves with the result when it closes', async () => {
    const result = host.open('weight-prompt', { product: 'Tomatoes' }, opener);
    expect(host.isOpen()).toBe(true);
    expect(host.current()?.kind).toBe('weight-prompt');
    expect(host.current()?.data).toEqual({ product: 'Tomatoes' });
    host.close('1.2500');
    await expect(result).resolves.toBe('1.2500');
    expect(host.isOpen()).toBe(false);
  });

  it('resolves undefined when it is closed without a result', async () => {
    const result = host.open('help');
    host.close();
    await expect(result).resolves.toBeUndefined();
  });

  it('returns the focus to the control that opened it', () => {
    opener.focus();
    void host.open('customer');
    scan.focus();
    host.close();
    expect(document.activeElement).toBe(opener);
  });

  it('takes the opener from the focused element when none is given', () => {
    opener.focus();
    void host.open('customer');
    expect(host.current()?.opener).toBe(opener);
  });

  it('returns the focus to the scan field when there was no opener', () => {
    void host.open('help');
    expect(host.current()?.opener).toBeNull();
    host.close();
    expect(document.activeElement).toBe(scan);
  });

  it('returns the focus to the scan field when the opener has gone', () => {
    void host.open('help', undefined, opener);
    opener.remove();
    host.close();
    expect(document.activeElement).toBe(scan);
  });

  it('returns the focus to the scan field when the opener is disabled', () => {
    void host.open('help', undefined, opener);
    opener.setAttribute('disabled', '');
    host.close();
    expect(document.activeElement).toBe(scan);
  });

  it('stacks dialogs, closes the top one first, and gives each its own result', async () => {
    const first = host.open('price-override', undefined, opener);
    const second = host.open('supervisor', undefined, opener);
    expect(host.dialogs().map((d) => d.kind)).toEqual(['price-override', 'supervisor']);
    expect(host.current()?.kind).toBe('supervisor');
    host.close({ approved: true });
    await expect(second).resolves.toEqual({ approved: true });
    expect(host.current()?.kind).toBe('price-override');
    host.close('done');
    await expect(first).resolves.toBe('done');
  });

  it('numbers each opening differently', () => {
    void host.open('help');
    void host.open('help');
    const ids = host.dialogs().map((d) => d.id);
    expect(new Set(ids).size).toBe(2);
  });

  it('closes everything, each with undefined', async () => {
    const a = host.open('payment');
    const b = host.open('supervisor');
    host.closeAll();
    expect(host.isOpen()).toBe(false);
    await expect(a).resolves.toBeUndefined();
    await expect(b).resolves.toBeUndefined();
  });

  it('closeIf closes only the dialog it was given, never whatever is on top by then (W-2)', async () => {
    const below = host.open('help', undefined, opener);
    const mine = host.currentId();
    const above = host.open('supervisor', undefined, opener);
    host.closeIf(mine, 'late');
    expect(host.dialogs()).toHaveLength(2);
    host.close('top');
    await expect(above).resolves.toBe('top');
    host.closeIf(mine, 'now');
    await expect(below).resolves.toBe('now');
    expect(host.isOpen()).toBe(false);
  });

  it('does nothing when closing with nothing open', () => {
    expect(() => host.close('x')).not.toThrow();
  });
});
