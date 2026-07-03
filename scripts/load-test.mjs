const baseUrl = process.env.LOAD_TEST_URL;
const cookie = process.env.LOAD_TEST_COOKIE;
const tree = process.env.LOAD_TEST_TREE;
const concurrency = Number(process.env.LOAD_TEST_CONCURRENCY ?? 500);

if (!baseUrl || !cookie || !tree) {
    console.error('Set LOAD_TEST_URL, LOAD_TEST_COOKIE and LOAD_TEST_TREE before running this production-like read-only test.');
    process.exit(2);
}

const started = performance.now();
const results = await Promise.all(Array.from({ length: concurrency }, async (_, index) => {
    const requestStarted = performance.now();
    try {
        const response = await fetch(`${baseUrl.replace(/\/$/, '')}/api/family/tree?scope=branch&depth=2`, {
            headers: {
                Accept: 'application/json',
                Cookie: cookie,
                'X-Family-Tree': tree,
                'X-Load-Test-Request': String(index + 1),
            },
        });
        await response.arrayBuffer();
        return { status: response.status, duration: performance.now() - requestStarted };
    } catch (error) {
        return { status: 0, duration: performance.now() - requestStarted, error: error.message };
    }
}));

const durations = results.map((item) => item.duration).sort((a, b) => a - b);
const statuses = Object.groupBy(results, (item) => String(item.status));
const percentile = (value) => durations[Math.min(durations.length - 1, Math.floor(durations.length * value))] ?? 0;
const summary = {
    requests: results.length,
    total_ms: Math.round(performance.now() - started),
    p50_ms: Math.round(percentile(0.50)),
    p95_ms: Math.round(percentile(0.95)),
    p99_ms: Math.round(percentile(0.99)),
    statuses: Object.fromEntries(Object.entries(statuses).map(([status, items]) => [status, items.length])),
};

console.log(JSON.stringify(summary, null, 2));
process.exit(results.some((item) => item.status !== 200) ? 1 : 0);
