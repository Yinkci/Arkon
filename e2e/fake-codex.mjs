import { writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const args = process.argv.slice(2);
if (args.includes('--version')) {
    console.log('codex-cli 0.160.0');
    process.exit(0);
}
if (args.includes('--help')) {
    console.log('--ignore-user-config --ephemeral');
    process.exit(0);
}
if (args.includes('login')) {
    console.error('Logged in using ChatGPT');
    process.exit(0);
}
let input = '';
process.stdin.on('data', (c) => (input += c));
process.stdin.on('end', () => {
    const fake = fileURLToPath(new URL('./fake-claude.mjs', import.meta.url));
    // Codex receives instructions and task together; the shared fixture expects only task data.
    const taskStart = input.lastIndexOf('Current blocks, top to bottom (JSON):');
    const task = taskStart < 0 ? input : input.slice(taskStart);
    const reply = spawnSync(process.execPath, [fake, '-p'], { input: task, encoding: 'utf8', env: process.env });
    if (reply.status !== 0) {
        process.exit(1);
    }
    const data = JSON.parse(reply.stdout).structured_output;
    writeFileSync(args[args.indexOf('--output-last-message') + 1], JSON.stringify(data));
    console.log(JSON.stringify({ type: 'turn.completed', usage: { input_tokens: 10, output_tokens: 5 } }));
});
