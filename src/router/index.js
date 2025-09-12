import {createRouter,createWebHistory} from "vue-router"

const routes=[
    {
          path: "/",
    name: "processor",
    component: () => import("../components/TimesheetProcessor.vue"),
    meta: { guest: true },
    }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router