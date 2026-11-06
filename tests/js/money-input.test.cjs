const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SRC = fs.readFileSync(
	path.join(__dirname, '..', '..', 'js', 'common', 'money-input.js'),
	'utf8',
);

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(SRC, sandbox);
const M = sandbox.SbdMoneyInput;

assert(M && typeof M.parse === 'function' && typeof M.toInput === 'function',
	'SbdMoneyInput exposes parse + toInput');

// parse: valid forms → minor units
[
	['12.99', 1299], ['12,99', 1299], ['0.01', 1], ['0', 0], ['128.40', 12840],
	[' 5 ', 500], ['7,5', 750], ['7.5', 750], ['1,000.00', null], // no grouping sep
].forEach(([inp, exp]) => {
	assert(M.parse(inp) === exp, `parse(${JSON.stringify(inp)}) === ${exp}, got ${M.parse(inp)}`);
});

// parse: invalid → null (never a silent number)
['abc', '1.2.3', '12,', '.5', '5.', '-1', '1e3', 'NaN', '€10', '10€', '1.234'].forEach((inp) => {
	assert(M.parse(inp) === null, `parse(${JSON.stringify(inp)}) === null, got ${M.parse(inp)}`);
});

// parse: empty → '' sentinel (field cleared)
assert(M.parse('') === '', 'parse("") is empty sentinel');
assert(M.parse('   ') === '', 'parse("   ") is empty sentinel');
assert(M.parse(null) === '', 'parse(null) is empty sentinel');
assert(M.parse(undefined) === '', 'parse(undefined) is empty sentinel');

// toInput: minor → display string
assert(M.toInput(1299) === '12.99', 'toInput(1299)');
assert(M.toInput(0) === '0.00', 'toInput(0)');
assert(M.toInput(5) === '0.05', 'toInput(5)');
assert(M.toInput(40000) === '400.00', 'toInput(40000)');
assert(M.toInput(null) === '', 'toInput(null)');
assert(M.toInput('') === '', 'toInput("")');
assert(M.toInput(undefined) === '', 'toInput(undefined)');

// round-trip
assert(M.parse(M.toInput(12840)) === 12840, 'round-trip 12840');

if (failures > 0) {
	process.exitCode = 1;
} else {
	process.stdout.write('money-input: all assertions passed\n');
}
