import * as Sentry from '@sentry/vue';

export const initSentry = import.meta.env.VITE_SENTRY_JS_DSN
    ? (app, router) => {
          Sentry.init({
              app,
              dsn: import.meta.env.VITE_SENTRY_JS_DSN,
              integrations: [
                  new Sentry.BrowserTracing({
                      // Set `tracePropagationTargets` to control for which URLs distributed tracing should be enabled
                      tracePropagationTargets: [
                          'localhost',
                          /^https:\/\/yourserver\.io\/api/,
                      ],
                      routingInstrumentation:
                          Sentry.vueRouterInstrumentation(router),
                  }),
                  new Sentry.Replay(),
              ],

              // Set tracesSampleRate to 1.0 to capture 100%
              // of transactions for performance monitoring.
              // We recommend adjusting this value in production
              tracesSampleRate:
                  import.meta.env.VITE_SENTRY_JS_TRACES_SAMPLE_RATE || 1.0,

              // Capture Replay for 10% of all sessions,
              // plus for 100% of sessions with an error
              replaysSessionSampleRate: 0.1,
              replaysOnErrorSampleRate: 1.0,
          });
      }
    : () => {};
