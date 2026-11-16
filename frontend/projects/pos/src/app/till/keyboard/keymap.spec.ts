import {
  KEYMAP,
  KeyLike,
  KeymapContext,
  formatBinding,
  matchKey,
  parseMultiplier,
  unavailableReason,
} from './keymap';

function key(name: string, mods: Partial<KeyLike> = {}): KeyLike {
  return { key: name, ctrlKey: false, altKey: false, metaKey: false, shiftKey: false, ...mods };
}

const ready: KeymapContext = {
  shiftOpen: true,
  lineCount: 2,
  hasSelectedLine: true,
  saleStatus: 'draft',
  dialogOpen: false,
};

describe('keymap (D-22, AC-80)', () => {
  const bindings: [string, KeyLike, string][] = [
    ['F2', key('F2'), 'pay'],
    ['F3', key('F3'), 'hold'],
    ['F4', key('F4'), 'recall'],
    ['F6', key('F6'), 'customer'],
    ['F7', key('F7'), 'refund'],
    ['F8', key('F8'), 'void'],
    ['F9', key('F9'), 'shift-menu'],
    ['/', key('/'), 'search'],
    ['Ctrl+K', key('k', { ctrlKey: true }), 'search'],
    ['Ctrl+K with caps lock', key('K', { ctrlKey: true }), 'search'],
    ['Delete', key('Delete'), 'remove-line'],
    ['Up', key('ArrowUp'), 'select-previous'],
    ['Down', key('ArrowDown'), 'select-next'],
    ['Enter', key('Enter'), 'confirm'],
    ['Esc', key('Escape'), 'cancel'],
    ['?', key('?', { shiftKey: true }), 'help'],
    ['F1', key('F1'), 'help'],
  ];

  it.each(bindings)('%s is bound to its action', (_name, event, action) => {
    expect(matchKey(event)?.entry.action).toBe(action);
  });

  it('leaves the browser-reserved keys alone', () => {
    for (const reserved of ['F5', 'F11', 'F12']) {
      expect(matchKey(key(reserved))).toBeNull();
    }
  });

  it('does not match a plain letter, or a modified binding', () => {
    expect(matchKey(key('k'))).toBeNull();
    expect(matchKey(key('a'))).toBeNull();
    expect(matchKey(key('F2', { ctrlKey: true }))).toBeNull();
    expect(matchKey(key('F2', { altKey: true }))).toBeNull();
    expect(matchKey(key('F2', { metaKey: true }))).toBeNull();
    expect(matchKey(key('k', { ctrlKey: true, shiftKey: true }))).toBeNull();
  });

  it('has one entry per action', () => {
    const actions = KEYMAP.map((e) => e.action);
    expect(new Set(actions).size).toBe(actions.length);
  });

  it('binds no key twice', () => {
    const seen = KEYMAP.flatMap((e) => e.keys.map((k) => formatBinding(k)));
    expect(new Set(seen).size).toBe(seen.length);
  });

  it('lets the field have `/`, `?`, Delete and the arrows unless it is empty', () => {
    expect(matchKey(key('/'))?.binding.typing).toBe('when-empty');
    expect(matchKey(key('?', { shiftKey: true }))?.binding.typing).toBe('when-empty');
    expect(matchKey(key('Delete'))?.binding.typing).toBe('when-empty');
    expect(matchKey(key('ArrowUp'))?.binding.typing).toBe('when-empty');
    expect(matchKey(key('F2'))?.binding.typing).toBeUndefined();
    expect(matchKey(key('k', { ctrlKey: true }))?.binding.typing).toBeUndefined();
    expect(matchKey(key('F1'))?.binding.typing).toBeUndefined();
  });

  it('leaves Enter to the focused control', () => {
    expect(matchKey(key('Enter'))?.entry.scope).toBe('control');
  });

  it('writes bindings the way the help list shows them', () => {
    expect(formatBinding({ key: 'F2' })).toBe('F2');
    expect(formatBinding({ key: 'k', ctrl: true })).toBe('Ctrl+K');
    expect(formatBinding({ key: 'ArrowUp' })).toBe('Up');
    expect(formatBinding({ key: 'Escape' })).toBe('Esc');
    expect(formatBinding({ key: '/' })).toBe('/');
  });

  describe('availability says why not (AC-80)', () => {
    const pay = KEYMAP.find((e) => e.action === 'pay')!;
    const hold = KEYMAP.find((e) => e.action === 'hold')!;
    const removeLine = KEYMAP.find((e) => e.action === 'remove-line')!;
    const recall = KEYMAP.find((e) => e.action === 'recall')!;
    const cancel = KEYMAP.find((e) => e.action === 'cancel')!;

    it('allows Pay on a sale with lines', () => {
      expect(unavailableReason(pay, ready)).toBeNull();
    });

    it('refuses Pay on an empty cart, with the reason', () => {
      expect(unavailableReason(pay, { ...ready, lineCount: 0 })).toBe(
        'Add a product before paying',
      );
    });

    it('refuses everything that needs a shift when none is open', () => {
      const closed = { ...ready, shiftOpen: false };
      expect(unavailableReason(pay, closed)).toBe('Open a shift first');
      expect(unavailableReason(recall, closed)).toBe('Open a shift first');
    });

    it('refuses Hold on a sale that is not draft or part paid', () => {
      expect(unavailableReason(hold, { ...ready, saleStatus: 'completed' })).toBe(
        'Only a draft or part-paid sale can be held',
      );
      expect(unavailableReason(hold, { ...ready, saleStatus: 'pending_payment' })).toBeNull();
    });

    it('refuses Delete with no line selected', () => {
      expect(unavailableReason(removeLine, { ...ready, hasSelectedLine: false })).toBe(
        'Select a line first',
      );
    });

    it('allows only Esc while a dialog is open', () => {
      const open = { ...ready, dialogOpen: true };
      expect(unavailableReason(pay, open)).toBe('Close this dialog first');
      expect(unavailableReason(cancel, open)).toBeNull();
    });
  });
});

describe('multiplier (AC-81)', () => {
  it('reads N* as the digits typed', () => {
    expect(parseMultiplier('3*')).toBe('3');
    expect(parseMultiplier('12*')).toBe('12');
    expect(parseMultiplier('999*')).toBe('999');
    expect(parseMultiplier(' 3* ')).toBe('3');
  });

  it('rejects everything else', () => {
    for (const bad of ['0*', '-1*', '1.5*', '1234*', '*', '3', '3 *', '03*', 'a*', '3**', '']) {
      expect(parseMultiplier(bad)).toBeNull();
    }
  });
});
